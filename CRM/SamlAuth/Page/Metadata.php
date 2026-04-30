<?php
declare(strict_types = 1);

use BlackBrickSoftware\CiviCRMSamlAuth\Service\ConfigProvider;
use BlackBrickSoftware\CiviCRMSamlAuth\Service\SamlService;

/**
 * Publishes SP SAML metadata. Returns 404 when SAML is disabled so the
 * endpoint doesn't leak the fact that the extension is installed.
 */
class CRM_SamlAuth_Page_Metadata extends CRM_Core_Page {

  public function run() {
    $config = \Civi::service(ConfigProvider::class);
    if ($config->mode() === ConfigProvider::MODE_DISABLED) {
      http_response_code(404);
      \CRM_Utils_System::setHttpHeader('Content-Type', 'text/plain; charset=utf-8');
      echo "Not Found\n";
      \CRM_Utils_System::civiExit();
    }

    try {
      $auth = \Civi::service(SamlService::class)->createAuth();
      $settings = $auth->getSettings();
      $metadata = $settings->getSPMetadata();
      $errors = $settings->validateMetadata($metadata);
      if ($errors) {
        throw new \RuntimeException('Invalid SP metadata: ' . implode(', ', $errors));
      }

      \CRM_Utils_System::setHttpHeader('Content-Type', 'text/xml; charset=utf-8');
      echo $metadata;
      \CRM_Utils_System::civiExit();
    }
    catch (\Throwable $e) {
      \CRM_Utils_System::setHttpHeader('Content-Type', 'text/plain; charset=utf-8');
      http_response_code(500);
      echo 'SAML metadata error: ' . $e->getMessage();
      \CRM_Utils_System::civiExit();
    }
  }

}
