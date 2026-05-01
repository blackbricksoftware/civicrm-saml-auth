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
      if (empty($existing['is_active'])) {
        throw new \RuntimeException(sprintf(
          'CiviCRM User account is disabled (user_id=%d, username=%s). Contact an administrator to re-enable.',
          $existing['id'],
          $existing['username']
        ));
      }
      \Civi::log()->debug('SAML: Matched existing user', ['user_id' => $existing['id'], 'identifier' => $identifier]);
      $contactId = (int) $existing['contact_id'];
      $this->syncContact((int) $existing['id'], $contactId, $samlData);
      $this->syncRoles((int) $existing['id'], $samlData);
      $this->flushNavigationCache($contactId);
      return (int) $existing['id'];
    }

    if (!$this->config->isProvisioningEnabled()) {
      throw new \RuntimeException(
        'No CiviCRM user matches SAML identity "' . $identifier . '" and auto-provisioning is disabled.'
      );
    }

    $userId = $this->provision($identifier, $samlData);
    $this->syncRoles($userId, $samlData);
    // Look up the contact_id we just created so we can prime its nav cache.
    $newUser = \Civi\Api4\User::get(FALSE)
      ->addWhere('id', '=', $userId)
      ->addSelect('contact_id')
      ->execute()
      ->first();
    if ($newUser) {
      $this->flushNavigationCache((int) $newUser['contact_id']);
    }
    return $userId;
  }

  /**
   * Bump the per-contact navigation cache key so the rendered admin menu is
   * rebuilt with the current role set on the very next page render. Without
   * this, a user whose roles just changed via syncRoles()/assignDefaultRoles()
   * sees their previous role's menu until something else (cv flush, navigation
   * write, etc.) invalidates the cache.
   *
   * Cheap — one cache write that flips the random key returned from
   * Navigation::getCacheKey().
   */
  private function flushNavigationCache(int $contactId): void {
    \CRM_Core_BAO_Navigation::resetContactNavigation($contactId);
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
    \CRM_Core_Session::singleton()->reset(1);

    if (!function_exists('_authx_uf')) {
      throw new \RuntimeException('authx extension is required for SAML login but is not active.');
    }
    _authx_uf()->loginSession($userId);

    \Civi::dispatcher()->dispatch(
      'civi.standalone.login',
      new LoginEvent('login_success', $userId)
    );
    \Civi::log()->info('SAML: Login completed', ['user_id' => $userId]);
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
    $ufName = $email !== '' ? $email : $username;

    // Pre-check both fields on civicrm_uf_match (User) so a unique-key
    // collision turns into a clear error instead of a raw DB exception
    // that leaves an orphan Contact behind. Most likely cause in practice:
    // an admin changed match_field or the username convention after some
    // users were already provisioned, so the matcher couldn't find them
    // by the new field but their email still collides.
    $this->assertNoUserConflict($username, $ufName);

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
    try {
      $user = \Civi\Api4\User::create(FALSE)
        ->addValue('username', $username)
        ->addValue('uf_name', $ufName)
        ->addValue('contact_id', $contact['id'])
        ->addValue('is_active', TRUE)
        ->execute()
        ->first();
    }
    catch (\Throwable $e) {
      // Clean up the orphan Contact + Email rows we just created. Anything
      // beyond the User INSERT failing leaves a dangling person record on
      // the contact list otherwise.
      \Civi\Api4\Email::delete(FALSE)
        ->addWhere('contact_id', '=', $contact['id'])
        ->execute();
      \Civi\Api4\Contact::delete(FALSE)
        ->addWhere('id', '=', $contact['id'])
        ->setUseTrash(FALSE)
        ->execute();
      throw $e;
    }

    \Civi::log()->info('SAML: Provisioned new user', [
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
        \Civi::log()->warning('SAML: Default role not found in CiviCRM', ['role' => $name]);
        continue;
      }
      \Civi\Api4\UserRole::create(FALSE)
        ->addValue('user_id', $userId)
        ->addValue('role_id', $available[$name]['id'])
        ->execute();
      \Civi::log()->debug('SAML: Assigned default role', ['user_id' => $userId, 'role' => $name]);
    }
  }

  /**
   * Update an existing user's Contact (first/last name) and primary email
   * from the SAML assertion. Treats the IdP as authoritative — local edits
   * inside CiviCRM are overwritten on every login. Skip silently if the
   * relevant ATTR_* setting is blank or the response carries no value.
   *
   * Match runs on username (nickname), so it's safe to update the email
   * here without breaking subsequent logins for this same user.
   */
  private function syncContact(int $userId, int $contactId, array $samlData): void {
    $attrs = $samlData['attributes'];
    $firstName = $this->readAttr($attrs, 'first_name');
    $lastName = $this->readAttr($attrs, 'last_name');

    $update = \Civi\Api4\Contact::update(FALSE)->addWhere('id', '=', $contactId);
    $touched = [];
    if ($firstName !== NULL) {
      $update->addValue('first_name', $firstName);
      $touched['first_name'] = $firstName;
    }
    if ($lastName !== NULL) {
      $update->addValue('last_name', $lastName);
      $touched['last_name'] = $lastName;
    }
    if ($touched !== []) {
      $update->execute();
      \Civi::log()->debug('SAML: Synced Contact name from SAML', ['contact_id' => $contactId] + $touched);
    }

    // Email: prefer match-field identifier when matching by email; otherwise
    // fall through the email attribute. Same precedence as provision().
    $email = $this->config->matchField() === ConfigProvider::MATCH_EMAIL
      ? $this->extractIdentifier($samlData)
      : ($this->readAttr($attrs, 'email') ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return;
    }

    $primary = \Civi\Api4\Email::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('is_primary', '=', TRUE)
      ->addSelect('id', 'email')
      ->execute()
      ->first();
    if (!$primary) {
      \Civi\Api4\Email::create(FALSE)
        ->addValue('contact_id', $contactId)
        ->addValue('email', $email)
        ->addValue('is_primary', TRUE)
        ->execute();
      \Civi::log()->debug('SAML: Created primary email from SAML', ['contact_id' => $contactId, 'email' => $email]);
    }
    elseif ($primary['email'] !== $email) {
      \Civi\Api4\Email::update(FALSE)
        ->addWhere('id', '=', $primary['id'])
        ->addValue('email', $email)
        ->execute();
      \Civi::log()->debug('SAML: Synced primary email from SAML', ['contact_id' => $contactId, 'old' => $primary['email'], 'new' => $email]);
    }

    // Keep User.uf_name aligned to the email (matches provision()'s convention).
    \Civi\Api4\User::update(FALSE)
      ->addWhere('id', '=', $userId)
      ->addValue('uf_name', $email)
      ->execute();
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

  /**
   * Refuse to provision when the proposed (username, uf_name) already exists
   * in civicrm_uf_match in any combination — that means the matcher should
   * have found this person but didn't (mismatched match_field, stale data,
   * etc.). Failing fast with a clear message beats a DB unique-key error.
   */
  private function assertNoUserConflict(string $username, string $ufName): void {
    $conflict = \Civi\Api4\User::get(FALSE)
      ->addClause('OR', ['username', '=', $username], ['uf_name', '=', $ufName])
      ->addSelect('id', 'username', 'uf_name', 'is_active')
      ->setLimit(1)
      ->execute()
      ->first();
    if ($conflict) {
      throw new \RuntimeException(sprintf(
        'Cannot provision SAML user — a CiviCRM User row already exists with username="%s" or uf_name="%s" (existing user_id=%d, username=%s, uf_name=%s, is_active=%s). The current saml_auth_match_field setting did not find this user. Either align the existing User\'s "%s" field to the SAML identity, change saml_auth_match_field, or remove the existing user.',
        $username,
        $ufName,
        $conflict['id'],
        $conflict['username'],
        $conflict['uf_name'],
        $conflict['is_active'] ? 'true' : 'false',
        $this->config->matchField() === ConfigProvider::MATCH_USERNAME ? 'username' : 'uf_name'
      ));
    }
  }

  private function syncRoles(int $userId, array $samlData): void {
    $attrName = $this->config->attr('roles');
    if ($attrName === NULL) {
      return;
    }
    $raw = $samlData['attributes'][$attrName] ?? NULL;
    if ($raw === NULL) {
      \Civi::log()->debug('SAML: Role attribute missing from response', ['attr' => $attrName]);
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
        \Civi::log()->warning('SAML: IdP role not found in CiviCRM', ['role' => $name]);
        continue;
      }
      \Civi\Api4\UserRole::create(FALSE)
        ->addValue('user_id', $userId)
        ->addValue('role_id', $availableRoles[$name]['id'])
        ->execute();
    }
  }

}
