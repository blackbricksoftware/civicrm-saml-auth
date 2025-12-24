<?php

namespace BlackBrickSoftware\CiviCRMSamlAuth;

use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils as SamlUtils;

/**
 * SAML Authentication Service
 *
 * Handles SAML2 authentication flows using OneLogin PHP-SAML library
 */
class SamlService {

  /**
   * Get SAML Auth instance configured with current settings
   *
   * @return Auth
   * @throws \Exception
   */
  public static function getAuth(): Auth {
    $settings = self::getSettings();
    return new Auth($settings);
  }

  /**
   * Get SAML configuration array
   *
   * @return array
   * @throws \Exception
   */
  public static function getSettings(): array {
    $config = \Civi::settings();

    // Get base URL for this CiviCRM installation
    $baseUrl = \CRM_Utils_System::url('', '', TRUE, NULL, FALSE);
    $baseUrl = rtrim($baseUrl, '/');

    $settings = [
      'strict' => TRUE,
      'debug' => (bool) $config->get('saml_auth_debug'),
      'sp' => [
        'entityId' => $config->get('saml_auth_sp_entity_id') ?: $baseUrl,
        'assertionConsumerService' => [
          'url' => \CRM_Utils_System::url('civicrm/saml/acs', '', TRUE, NULL, FALSE),
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],
        'singleLogoutService' => [
          'url' => \CRM_Utils_System::url('civicrm/saml/sls', '', TRUE, NULL, FALSE),
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
      ],
      'idp' => [
        'entityId' => $config->get('saml_auth_idp_entity_id'),
        'singleSignOnService' => [
          'url' => $config->get('saml_auth_idp_sso_url'),
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'x509cert' => $config->get('saml_auth_idp_x509_cert'),
      ],
      'security' => [
        'nameIdEncrypted' => FALSE,
        'authnRequestsSigned' => FALSE,
        'logoutRequestSigned' => FALSE,
        'logoutResponseSigned' => FALSE,
        'signMetadata' => FALSE,
        'wantMessagesSigned' => FALSE,
        'wantAssertionsSigned' => TRUE,
        'wantAssertionsEncrypted' => FALSE,
        'wantNameIdEncrypted' => FALSE,
        'requestedAuthnContext' => TRUE,
        'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
      ],
    ];

    // Add SLO URL if configured
    $sloUrl = $config->get('saml_auth_idp_slo_url');
    if (!empty($sloUrl)) {
      $settings['idp']['singleLogoutService'] = [
        'url' => $sloUrl,
        'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
      ];
    }

    return $settings;
  }

  /**
   * Check if SAML authentication is enabled
   *
   * @return bool
   */
  public static function isEnabled(): bool {
    return (bool) \Civi::settings()->get('saml_auth_enabled');
  }

  /**
   * Check if SSO enforcement is enabled
   *
   * @return bool
   */
  public static function isSsoEnforced(): bool {
    return (bool) \Civi::settings()->get('saml_auth_enforce_sso');
  }

  /**
   * Check if bypass key is valid
   *
   * @param string|null $providedKey
   * @return bool
   */
  public static function isValidBypassKey(?string $providedKey): bool {
    if (empty($providedKey)) {
      return FALSE;
    }

    $configuredKey = \Civi::settings()->get('saml_auth_bypass_key');

    if (empty($configuredKey)) {
      return FALSE;
    }

    return hash_equals($configuredKey, $providedKey);
  }

  /**
   * Process SAML response and authenticate user
   *
   * @return array User attributes from SAML response
   * @throws \Exception
   */
  public static function processResponse(): array {
    $auth = self::getAuth();
    $auth->processResponse();

    if (!$auth->isAuthenticated()) {
      $errors = $auth->getErrors();
      $errorReason = $auth->getLastErrorReason();
      throw new \Exception('SAML authentication failed: ' . implode(', ', $errors) . ' - ' . $errorReason);
    }

    return [
      'attributes' => $auth->getAttributes(),
      'nameId' => $auth->getNameId(),
      'sessionIndex' => $auth->getSessionIndex(),
    ];
  }

  /**
   * Find or create CiviCRM user from SAML attributes
   *
   * @param array $samlData
   * @return int Contact ID
   * @throws \Exception
   */
  public static function findOrCreateUser(array $samlData): int {
    $lookupField = \Civi::settings()->get('saml_auth_user_lookup_field') ?: 'email';
    $identifier = $samlData['nameId'];
    $attributes = $samlData['attributes'];

    // Find existing user
    $contactId = NULL;

    if ($lookupField === 'email') {
      // Validate email format
      if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        throw new \Exception('Invalid email format in SAML response');
      }

      // Try to find existing contact by email
      $contact = \Civi\Api4\Contact::get(FALSE)
        ->addWhere('email.email', '=', $identifier)
        ->addSelect('id')
        ->execute()
        ->first();

      if ($contact) {
        $contactId = $contact['id'];
      }
    }
    elseif ($lookupField === 'username') {
      // Look up by username in User table
      $user = \Civi\Api4\User::get(FALSE)
        ->addWhere('username', '=', $identifier)
        ->addSelect('contact_id')
        ->execute()
        ->first();

      if ($user) {
        $contactId = $user['contact_id'];
      }
    }

    // If user not found and provisioning is disabled, throw error
    if (!$contactId && !self::isProvisioningEnabled()) {
      throw new \Exception(
        'User not found and automatic provisioning is disabled. ' .
        'Please contact your administrator to create your account before using SSO.'
      );
    }

    // If user found, return contact ID
    if ($contactId) {
      self::debug('User found', ['contact_id' => $contactId, 'lookup_field' => $lookupField]);
      return $contactId;
    }

    // Create new user (provisioning is enabled)
    self::debug('Creating new user via provisioning', ['identifier' => $identifier]);

    $firstName = $attributes['firstName'][0] ?? $attributes['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname'][0] ?? '';
    $lastName = $attributes['lastName'][0] ?? $attributes['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname'][0] ?? '';

    if (empty($firstName) && empty($lastName)) {
      // Use identifier prefix as display name if no name attributes
      $parts = explode('@', $identifier);
      $firstName = $parts[0];
    }

    // Determine email for contact
    $email = $lookupField === 'email' ? $identifier : ($attributes['email'][0] ?? '');

    $newContact = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', $firstName)
      ->addValue('last_name', $lastName);

    // Add email if available
    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $newContact->addChain('email', \Civi\Api4\Email::create(FALSE)
        ->addValue('contact_id', '$id')
        ->addValue('email', $email)
        ->addValue('is_primary', TRUE)
      );
    }

    $newContact = $newContact->execute()->first();
    $contactId = $newContact['id'];

    self::debug('Created new contact', [
      'contact_id' => $contactId,
      'identifier' => $identifier,
    ]);

    return $contactId;
  }

  /**
   * Check if user provisioning is enabled
   *
   * @return bool
   */
  public static function isProvisioningEnabled(): bool {
    $setting = \Civi::settings()->get('saml_auth_enable_provisioning');
    // Default to TRUE if not set
    return $setting !== FALSE && $setting !== 0;
  }

  /**
   * Assign roles to user from SAML attributes
   *
   * @param int $userId CiviCRM User ID
   * @param array $samlData SAML response data
   * @return void
   */
  public static function assignRoles(int $userId, array $samlData): void {
    $roleAttribute = \Civi::settings()->get('saml_auth_role_attribute');

    if (empty($roleAttribute)) {
      return; // Role assignment disabled
    }

    $attributes = $samlData['attributes'];

    if (!isset($attributes[$roleAttribute])) {
      self::debug('Role attribute not found in SAML response', ['attribute' => $roleAttribute]);
      return;
    }

    $samlRoles = $attributes[$roleAttribute];
    if (!is_array($samlRoles)) {
      $samlRoles = [$samlRoles];
    }

    self::debug('Processing role assignment', [
      'user_id' => $userId,
      'saml_roles' => $samlRoles,
    ]);

    // Get all available roles in CiviCRM
    $availableRoles = \Civi\Api4\Role::get(FALSE)
      ->addSelect('id', 'name')
      ->execute()
      ->indexBy('name');

    // Clear existing roles for this user
    \Civi\Api4\UserRole::delete(FALSE)
      ->addWhere('user_id', '=', $userId)
      ->execute();

    // Assign new roles
    foreach ($samlRoles as $roleName) {
      $roleName = trim($roleName);

      if (isset($availableRoles[$roleName])) {
        \Civi\Api4\UserRole::create(FALSE)
          ->addValue('user_id', $userId)
          ->addValue('role_id', $availableRoles[$roleName]['id'])
          ->execute();

        self::debug('Assigned role', [
          'user_id' => $userId,
          'role' => $roleName,
        ]);
      }
      else {
        self::debug('Role not found in CiviCRM', [
          'role' => $roleName,
          'available_roles' => array_keys($availableRoles->getArrayCopy()),
        ]);
      }
    }
  }

  /**
   * Check if debug mode is enabled
   *
   * @return bool
   */
  public static function isDebugEnabled(): bool {
    return (bool) \Civi::settings()->get('saml_auth_debug');
  }

  /**
   * Log debug message if debug mode is enabled
   *
   * @param string $message
   * @param array $context
   */
  public static function debug(string $message, array $context = []): void {
    if (self::isDebugEnabled()) {
      \Civi::log()->debug('SAML: ' . $message, $context);
    }
  }

}
