<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSamlAuth\Service;

use OneLogin\Saml2\Auth;
use Civi\Standalone\Event\LoginEvent;

/**
 * Orchestrates SAML authentication: builds the OneLogin Auth object,
 * processes the response, finds/provisions the Civi user, syncs roles,
 * regenerates the session, and logs the user in via authx.
 */
class SamlService {

  public function __construct(
    private readonly ConfigProvider $config,
    private readonly UserMatcher $matcher,
  ) {}

  public function createAuth(): Auth {
    return new Auth($this->config->buildOneLoginSettings());
  }

  /**
   * Process a SAML response on the current ACS request.
   *
   * @return array{attributes: array, nameId: ?string, sessionIndex: ?string}
   */
  public function processResponse(Auth $auth): array {
    $auth->processResponse();
    if (!$auth->isAuthenticated()) {
      $reason = $auth->getLastErrorReason() ?? '';
      $errors = $auth->getErrors() ?: [];
      throw new \RuntimeException(
        'SAML authentication failed: ' . implode(', ', $errors) . ($reason !== '' ? ' — ' . $reason : '')
      );
    }

    return [
      'attributes' => $auth->getAttributes() ?: [],
      'nameId' => $auth->getNameId() ?: NULL,
      'sessionIndex' => $auth->getSessionIndex() ?: NULL,
    ];
  }

  /**
   * Find a User for this SAML identity (or provision a new one when
   * provisioning is enabled) and sync roles. Returns the Civi User ID.
   */
  public function findOrProvisionUser(array $samlData): int {
    $identifier = $this->extractIdentifier($samlData);
    $existing = $this->matcher->find($identifier);
    if ($existing) {
      $this->config->debug('Matched existing user', ['user_id' => $existing['id'], 'identifier' => $identifier]);
      $this->syncRoles((int) $existing['id'], $samlData);
      return (int) $existing['id'];
    }

    if (!$this->config->isProvisioningEnabled()) {
      throw new \RuntimeException(
        'No CiviCRM user matches SAML identity "' . $identifier . '" and auto-provisioning is disabled.'
      );
    }

    $userId = $this->provision($identifier, $samlData);
    $this->syncRoles($userId, $samlData);
    return $userId;
  }

  /**
   * Regenerate the session ID, switch the session to the new user via
   * authx, and fire civi.standalone.login so other subscribers can react.
   */
  public function completeLogin(int $userId): void {
    // Regenerate to avoid session fixation: any attacker who fixed the
    // pre-login ID now holds a useless token.
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_regenerate_id(TRUE);
    }
    \CRM_Core_Session::getInstance()->reset(1);

    if (!function_exists('_authx_uf')) {
      throw new \RuntimeException('authx extension is required for SAML login but is not active.');
    }
    _authx_uf()->loginSession($userId);

    \Civi::dispatcher()->dispatch(
      new LoginEvent('login_success', $userId),
      'civi.standalone.login'
    );
    $this->config->debug('Login completed via SAML', ['user_id' => $userId]);
  }

  /**
   * Choose the identity used for matching. For MATCH_USERNAME we use the
   * configured username attribute (falling back to NameID). For MATCH_EMAIL
   * we prefer the email attribute, falling back to NameID.
   */
  private function extractIdentifier(array $samlData): string {
    $attrs = $samlData['attributes'];
    $nameId = (string) ($samlData['nameId'] ?? '');

    if ($this->config->matchField() === ConfigProvider::MATCH_USERNAME) {
      $attr = $this->config->attr('username');
      $value = $attr !== NULL ? (string) ($attrs[$attr][0] ?? '') : '';
      $value = $value !== '' ? $value : $nameId;
    }
    else {
      $attr = $this->config->attr('email');
      $value = $attr !== NULL ? (string) ($attrs[$attr][0] ?? '') : '';
      $value = $value !== '' ? $value : $nameId;
      if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        throw new \RuntimeException('SAML response did not supply a valid email address for matching.');
      }
    }

    if ($value === '') {
      throw new \RuntimeException('SAML response contained no usable identifier for matching.');
    }
    return $value;
  }

  private function provision(string $identifier, array $samlData): int {
    $attrs = $samlData['attributes'];
    $matchField = $this->config->matchField();

    $firstName = $this->readAttr($attrs, 'first_name');
    $lastName = $this->readAttr($attrs, 'last_name');
    $email = $matchField === ConfigProvider::MATCH_EMAIL
      ? $identifier
      : $this->readAttr($attrs, 'email') ?? '';
    $username = $matchField === ConfigProvider::MATCH_USERNAME
      ? $identifier
      : ($this->readAttr($attrs, 'username') ?? $this->deriveUsername($email ?: $identifier));

    // Contact first — User.contact_id FK needs it to exist.
    $contactCreate = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual');
    if ($firstName !== NULL) {
      $contactCreate->addValue('first_name', $firstName);
    }
    if ($lastName !== NULL) {
      $contactCreate->addValue('last_name', $lastName);
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $contactCreate->addChain('email', \Civi\Api4\Email::create(FALSE)
        ->addValue('contact_id', '$id')
        ->addValue('email', $email)
        ->addValue('is_primary', TRUE));
    }
    $contact = $contactCreate->execute()->first();

    $username = $this->ensureUniqueUsername($username);
    $user = \Civi\Api4\User::create(FALSE)
      ->addValue('username', $username)
      ->addValue('uf_name', $email !== '' ? $email : $username)
      ->addValue('contact_id', $contact['id'])
      ->addValue('is_active', TRUE)
      ->execute()
      ->first();

    $this->config->debug('Provisioned new user', [
      'user_id' => $user['id'],
      'contact_id' => $contact['id'],
      'username' => $username,
    ]);

    $this->assignDefaultRoles((int) $user['id']);
    return (int) $user['id'];
  }

  /**
   * Apply saml_auth_default_roles to a freshly-provisioned user. Called only
   * from provision() — never on subsequent logins, so admins can adjust roles
   * inside CiviCRM and the changes survive future SSO logins. Roles named in
   * the setting that don't exist in CiviCRM are logged and skipped (never
   * auto-created), matching the syncRoles() convention.
   */
  private function assignDefaultRoles(int $userId): void {
    $names = $this->config->defaultRoles();
    if ($names === []) {
      return;
    }
    $available = \Civi\Api4\Role::get(FALSE)
      ->addSelect('id', 'name')
      ->execute()
      ->indexBy('name');

    foreach ($names as $name) {
      if (!isset($available[$name])) {
        $this->config->debug('Default role not found in CiviCRM', ['role' => $name]);
        continue;
      }
      \Civi\Api4\UserRole::create(FALSE)
        ->addValue('user_id', $userId)
        ->addValue('role_id', $available[$name]['id'])
        ->execute();
      $this->config->debug('Assigned default role', ['user_id' => $userId, 'role' => $name]);
    }
  }

  private function readAttr(array $attrs, string $field): ?string {
    $name = $this->config->attr($field);
    if ($name === NULL) {
      return NULL;
    }
    $value = $attrs[$name][0] ?? NULL;
    if ($value === NULL || $value === '') {
      return NULL;
    }
    return (string) $value;
  }

  private function deriveUsername(string $seed): string {
    $candidate = preg_replace('/[^A-Za-z0-9._-]+/', '.', strtolower(explode('@', $seed)[0]));
    $candidate = trim((string) $candidate, '.');
    return $candidate !== '' ? $candidate : 'user';
  }

  private function ensureUniqueUsername(string $base): string {
    $candidate = $base;
    $i = 1;
    while ($this->usernameExists($candidate)) {
      $candidate = $base . $i;
      $i++;
    }
    return $candidate;
  }

  private function usernameExists(string $username): bool {
    return \Civi\Api4\User::get(FALSE)
      ->addWhere('username', '=', $username)
      ->selectRowCount()
      ->execute()
      ->count() > 0;
  }

  private function syncRoles(int $userId, array $samlData): void {
    $attrName = $this->config->attr('roles');
    if ($attrName === NULL) {
      return;
    }
    $raw = $samlData['attributes'][$attrName] ?? NULL;
    if ($raw === NULL) {
      $this->config->debug('Role attribute missing from response', ['attr' => $attrName]);
      return;
    }
    $samlRoles = array_map(static fn($v) => trim((string) $v), is_array($raw) ? $raw : [$raw]);
    $samlRoles = array_filter($samlRoles, static fn($v) => $v !== '');

    $availableRoles = \Civi\Api4\Role::get(FALSE)
      ->addSelect('id', 'name')
      ->execute()
      ->indexBy('name');

    \Civi\Api4\UserRole::delete(FALSE)
      ->addWhere('user_id', '=', $userId)
      ->execute();

    foreach ($samlRoles as $name) {
      if (!isset($availableRoles[$name])) {
        $this->config->debug('SAML role not found in CiviCRM', ['role' => $name]);
        continue;
      }
      \Civi\Api4\UserRole::create(FALSE)
        ->addValue('user_id', $userId)
        ->addValue('role_id', $availableRoles[$name]['id'])
        ->execute();
    }
  }

}
