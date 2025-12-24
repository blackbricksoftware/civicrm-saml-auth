<?php

use CRM_SamlAuth_ExtensionUtil as E;
use BlackBrickSoftware\CiviCRMSamlAuth\SamlService;

/**
 * SAML Login Page - Initiates SSO flow
 */
class CRM_SamlAuth_Page_Login extends CRM_Core_Page {

  public function run() {
    try {
      if (!SamlService::isEnabled()) {
        throw new \Exception('SAML authentication is not enabled');
      }

      SamlService::debug('Initiating SAML login');

      $auth = SamlService::getAuth();
      $auth->login();

      // login() should redirect, but if it doesn't, exit
      exit;
    }
    catch (\Exception $e) {
      SamlService::debug('SAML login error', ['error' => $e->getMessage()]);
      \CRM_Core_Error::statusBounce('SAML login failed: ' . $e->getMessage());
    }
  }

}
