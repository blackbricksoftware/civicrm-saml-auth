<?php
declare(strict_types = 1);

use BlackBrickSoftware\CiviCRMSamlAuth\Service\ConfigProvider;
use BlackBrickSoftware\CiviCRMSamlAuth\Service\RelayStateValidator;
use BlackBrickSoftware\CiviCRMSamlAuth\Service\SamlService;

/**
 * SAML Assertion Consumer Service. Accepts both SP-initiated responses
 * (our prior AuthnRequest) and unsolicited IdP-initiated responses;
 * OneLogin's processResponse handles both cases.
 */
class CRM_SamlAuth_Page_Acs extends CRM_Core_Page {

  public function run() {
    $config = \Civi::service(ConfigProvider::class);

    try {
      if (!$config->isEnabled()) {
        throw new \RuntimeException('SAML authentication is not enabled.');
      }

      $service = \Civi::service(SamlService::class);
      $auth = $service->createAuth();

      \Civi::log()->debug('SAML: Processing SAML response');
      $samlData = $service->processResponse($auth);
      \Civi::log()->debug('SAML: SAML response valid', ['nameId' => $samlData['nameId']]);

      $userId = $service->findOrProvisionUser($samlData);
      $service->completeLogin($userId);

      $relay = isset($_POST['RelayState']) ? (string) $_POST['RelayState'] : NULL;
      $validator = \Civi::service(RelayStateValidator::class);
      $target = $validator->validate($relay) ?? $config->defaultReturnUrl();

      \CRM_Utils_System::redirect($target);
    }
    catch (\Throwable $e) {
      $ref = $config->logError('ACS error', $e);
      \CRM_Core_Error::statusBounce(sprintf(
        'SAML authentication failed. Please contact an administrator. (Ref: %s)',
        $ref
      ));
    }
  }

}
