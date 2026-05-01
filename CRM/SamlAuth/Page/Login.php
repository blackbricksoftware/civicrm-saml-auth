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

      // OneLogin's Auth::login() defaults RelayState to getSelfRoutedURLNoQuery()
      // when $relayState is null/empty — i.e. the URL of THIS page, /civicrm/saml/login.
      // That would loop the user right back through SAML on every successful login.
      // We pass our default return URL explicitly so RelayState is always something
      // sensible. Caller-supplied ?return= still wins, and the ACS validates the
      // RelayState against the allowlist before honoring it.
      $relayState = isset($_GET['return']) && $_GET['return'] !== ''
        ? (string) $_GET['return']
        : $config->defaultReturnUrl();
      \Civi::log()->debug('SAML: Initiating SP-init SAML login', ['relay' => $relayState]);

      $service = \Civi::service(SamlService::class);
      $auth = $service->createAuth();
      $auth->login($relayState);
      // login() performs a redirect + exit. Fall-through is defensive only.
      \CRM_Utils_System::civiExit();
    }
    catch (\Throwable $e) {
      $ref = $config->logError('SP-init login error', $e);
      \CRM_Core_Error::statusBounce(sprintf(
        'SAML login failed. Please contact an administrator. (Ref: %s)',
        $ref
      ));
    }
  }

}
