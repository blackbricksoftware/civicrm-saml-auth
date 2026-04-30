<?php
declare(strict_types = 1);

use BlackBrickSoftware\CiviCRMSamlAuth\Service\ConfigProvider;
use BlackBrickSoftware\CiviCRMSamlAuth\Service\SamlService;

/**
 * SP-initiated login: builds an AuthnRequest and redirects to the IdP.
 *
 * Accepts ?return=... on the query string and passes it as RelayState.
 * The ACS handler validates RelayState against the allowlist before using
 * it as the post-login redirect target.
 */
class CRM_SamlAuth_Page_Login extends CRM_Core_Page {

  public function run() {
    $config = \Civi::service(ConfigProvider::class);

    try {
      if (!$config->isEnabled()) {
        throw new \RuntimeException('SAML authentication is not enabled.');
      }

      $relayState = isset($_GET['return']) ? (string) $_GET['return'] : NULL;
      $config->debug('Initiating SP-init SAML login', ['relay' => $relayState]);

      $service = \Civi::service(SamlService::class);
      $auth = $service->createAuth();
      $auth->login($relayState);
      // login() performs a redirect + exit. Fall-through is defensive only.
      \CRM_Utils_System::civiExit();
    }
    catch (\Throwable $e) {
      $config->debug('SAML login error', ['error' => $e->getMessage()]);
      \CRM_Core_Error::statusBounce('SAML login failed: ' . $e->getMessage());
    }
  }

}
