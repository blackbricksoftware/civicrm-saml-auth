<?php
declare(strict_types = 1);

use BlackBrickSoftware\CiviCRMSamlAuth\Service\ConfigProvider;

/**
 * Publishes SP SAML metadata. Returns 404 when SAML is disabled so the
 * endpoint doesn't leak the fact that the extension is installed.
 *
 * Built directly from a Settings instance constructed with
 * $spValidationOnly=TRUE — the natural workflow is to render SP metadata
 * BEFORE the IdP is configured (so the SP admin can hand the metadata
 * URL/XML to the IdP admin), and the standard Auth pathway requires the
 * IdP block to validate. SP-only mode skips that and lets us serve
 * metadata even when IdP entity/SSO/cert are blank.
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
      $settings = new \OneLogin\Saml2\Settings($config->buildOneLoginSettings(), TRUE);
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
      $ref = $config->logError('Metadata error', $e);
      \CRM_Utils_System::setHttpHeader('Content-Type', 'text/plain; charset=utf-8');
      http_response_code(500);
      printf("SAML metadata error. Please contact an administrator. (Ref: %s)\n", $ref);
      \CRM_Utils_System::civiExit();
    }
  }

}
