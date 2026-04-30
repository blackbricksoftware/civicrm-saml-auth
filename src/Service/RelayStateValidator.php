<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSamlAuth\Service;

/**
 * Validates SAML RelayState values against a configured allowlist of
 * URL prefixes. Default-deny: unknown RelayState → return NULL and log;
 * caller should fall back to the configured default return URL.
 */
class RelayStateValidator {

  public function __construct(private readonly ConfigProvider $config) {}

  /**
   * @return string|null The accepted URL, or NULL when validation fails.
   */
  public function validate(?string $relayState): ?string {
    if ($relayState === NULL || $relayState === '') {
      return NULL;
    }

    $candidate = trim($relayState);
    if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
      $this->config->debug('RelayState rejected — not a valid URL', ['relay' => $candidate]);
      return NULL;
    }

    $baseUrl = rtrim((string) \CRM_Utils_System::url('', '', TRUE, NULL, FALSE), '/');
    $allow = $this->config->relayStateAllowlist();
    if ($allow === []) {
      $allow = [$baseUrl];
    }

    foreach ($allow as $prefix) {
      $prefix = rtrim($prefix, '/');
      if ($prefix === '') {
        continue;
      }
      if ($candidate === $prefix || str_starts_with($candidate, $prefix . '/')) {
        return $candidate;
      }
    }

    $this->config->debug('RelayState rejected — not in allowlist', ['relay' => $candidate, 'allow' => $allow]);
    return NULL;
  }

}
