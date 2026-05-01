<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSamlAuth\Service;

/**
 * Reads saml_auth_* settings and reports which are env-overridden.
 *
 * All reads go through Civi::settings(), so the resolution order that
 * CiviCRM's SettingsManager already enforces applies:
 *   env var (`global_name`) → $civicrm_setting → DB → default.
 *
 * isOverridden() lets the settings UI freeze a field when env or
 * civicrm.settings.php has supplied a mandatory value.
 */
class ConfigProvider {

  public const MODE_DISABLED = 'disabled';
  public const MODE_OPTIONAL = 'optional';
  public const MODE_REQUIRED = 'required';

  public const MATCH_USERNAME = 'username';
  public const MATCH_EMAIL = 'email';

  /**
   * Settings that carry private/secret material and must be masked in
   * the UI when env-supplied.
   */
  private const SECRET_KEYS = [
    'saml_auth_idp_x509_cert',
    'saml_auth_sp_private_key',
  ];

  public function get(string $key): mixed {
    return \Civi::settings()->get($key);
  }

  public function isOverridden(string $key): bool {
    return \Civi::settings()->getMandatory($key) !== NULL;
  }

  public function isSecret(string $key): bool {
    return in_array($key, self::SECRET_KEYS, TRUE);
  }

  public function mode(): string {
    $mode = (string) $this->get('saml_auth_mode');
    return in_array($mode, [self::MODE_DISABLED, self::MODE_OPTIONAL, self::MODE_REQUIRED], TRUE)
      ? $mode
      : self::MODE_DISABLED;
  }

  public function isEnabled(): bool {
    return $this->mode() !== self::MODE_DISABLED;
  }

  public function matchField(): string {
    $field = (string) $this->get('saml_auth_match_field');
    return $field === self::MATCH_USERNAME ? self::MATCH_USERNAME : self::MATCH_EMAIL;
  }

  public function isProvisioningEnabled(): bool {
    return $this->readBool('saml_auth_provisioning_enabled');
  }

  /**
   * Boolean coercion tolerant of env-sourced strings.
   */
  private function readBool(string $key): bool {
    $v = $this->get($key);
    if (is_bool($v)) {
      return $v;
    }
    if (is_int($v)) {
      return $v !== 0;
    }
    if ($v === NULL || $v === '') {
      return FALSE;
    }
    return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], TRUE);
  }

  public function attr(string $field): ?string {
    $key = 'saml_auth_attr_' . $field;
    $val = trim((string) $this->get($key));
    return $val === '' ? NULL : $val;
  }

  /**
   * @return string[] URL prefixes. Empty array means "fall back to base URL only".
   */
  public function relayStateAllowlist(): array {
    return $this->parseList('saml_auth_relaystate_allowlist');
  }

  /**
   * @return string[] CiviCRM Role names to assign at provision time.
   */
  public function defaultRoles(): array {
    return $this->parseList('saml_auth_default_roles');
  }

  /**
   * Splits a setting value on commas or whitespace.
   */
  private function parseList(string $key): array {
    $raw = (string) $this->get($key);
    $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter(array_map('trim', $parts)));
  }

  public function defaultReturnUrl(): string {
    return \CRM_Utils_System::url('civicrm/home', '', TRUE, NULL, FALSE);
  }

  /**
   * Assembles the OneLogin\Saml2\Auth settings array.
   */
  public function buildOneLoginSettings(): array {
    $baseUrl = rtrim((string) \CRM_Utils_System::url('', '', TRUE, NULL, FALSE), '/');

    $spCert = (string) $this->get('saml_auth_sp_x509_cert');
    $spKey = (string) $this->get('saml_auth_sp_private_key');
    $signRequests = $this->readBool('saml_auth_sign_requests') && $spCert !== '' && $spKey !== '';

    return [
      'strict' => TRUE,
      // Hard-coded off — OneLogin's debug flag only adds an `echo` of XML
      // schema errors in Utils::validateXML(). Diagnostic content we care
      // about is already surfaced via $auth->getErrors() / getLastErrorReason().
      'debug' => FALSE,
      'sp' => [
        'entityId' => ((string) $this->get('saml_auth_sp_entity_id')) ?: $baseUrl,
        'assertionConsumerService' => [
          'url' => \CRM_Utils_System::url('civicrm/saml/acs', '', TRUE, NULL, FALSE),
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],
        'NameIDFormat' => $this->matchField() === self::MATCH_EMAIL
          ? 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress'
          : 'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified',
        'x509cert' => $spCert,
        'privateKey' => $spKey,
      ],
      'idp' => [
        'entityId' => (string) $this->get('saml_auth_idp_entity_id'),
        'singleSignOnService' => [
          'url' => (string) $this->get('saml_auth_idp_sso_url'),
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'x509cert' => (string) $this->get('saml_auth_idp_x509_cert'),
      ],
      'security' => [
        'nameIdEncrypted' => FALSE,
        'authnRequestsSigned' => $signRequests,
        'logoutRequestSigned' => FALSE,
        'logoutResponseSigned' => FALSE,
        'signMetadata' => $signRequests,
        'wantMessagesSigned' => FALSE,
        'wantAssertionsSigned' => TRUE,
        'wantAssertionsEncrypted' => FALSE,
        'wantNameIdEncrypted' => FALSE,
        'requestedAuthnContext' => TRUE,
        'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
      ],
    ];
  }

  /**
   * Log a caught exception at error level and return a short reference
   * code. The user-facing message includes the code; admins grep
   * "Ref: <code>" in container logs to find the full exception + stack
   * trace tied to a user complaint.
   */
  public function logError(string $message, \Throwable $e, array $context = []): string {
    $ref = strtoupper(bin2hex(random_bytes(3)));
    \Civi::log()->error('SAML: {message} [Ref: {ref}] {error}', [
      'message' => $message,
      'ref' => $ref,
      'error' => $e->getMessage(),
      'exception' => $e,
    ] + $context);
    return $ref;
  }

}
