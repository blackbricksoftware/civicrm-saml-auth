<?php

require_once 'saml_auth.civix.php';

use CRM_SamlAuth_ExtensionUtil as E;
use BlackBrickSoftware\CiviCRMSamlAuth\SamlService;

// Load autoloader (if necessary)
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
  require_once $autoloadPath;
}


/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function saml_auth_civicrm_config(&$config): void {
  _saml_auth_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function saml_auth_civicrm_install(): void {
  _saml_auth_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function saml_auth_civicrm_enable(): void {
  _saml_auth_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_buildForm().
 *
 * Intercept login form to enforce SSO or add SAML login option
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_buildForm/
 */
function saml_auth_civicrm_buildForm($formName, &$form): void {
  if ($formName !== 'CRM_Standaloneusers_Form_Login') {
    return;
  }

  if (!SamlService::isEnabled()) {
    return;
  }

  // Check for bypass key
  $bypassKey = $_GET['bypass'] ?? $_POST['bypass'] ?? NULL;
  $isBypassValid = SamlService::isValidBypassKey($bypassKey);

  if (SamlService::isSsoEnforced() && !$isBypassValid) {
    // SSO is enforced and no valid bypass key - redirect to SAML login
    SamlService::debug('SSO enforced, redirecting to SAML login');
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/saml/login', '', TRUE, NULL, FALSE));
  }

  // Add "Login with SSO" button to the login form
  $samlLoginUrl = CRM_Utils_System::url('civicrm/saml/login', '', TRUE, NULL, FALSE);
  $form->assign('samlLoginUrl', $samlLoginUrl);

  // Add template resource to inject SSO button
  CRM_Core_Region::instance('page-body')->add([
    'template' => 'CRM/SamlAuth/SsoLoginButton.tpl',
  ]);
}

/**
 * Implements hook_civicrm_pageRun().
 *
 * Add SAML login link to various pages
 */
function saml_auth_civicrm_pageRun(&$page): void {
  $pageName = get_class($page);

  // Add SSO button to login page if not enforced
  if ($pageName === 'CRM_Standaloneusers_Page_Login' && SamlService::isEnabled() && !SamlService::isSsoEnforced()) {
    $samlLoginUrl = CRM_Utils_System::url('civicrm/saml/login', '', TRUE, NULL, FALSE);
    $page->assign('samlLoginUrl', $samlLoginUrl);

    CRM_Core_Region::instance('page-body')->add([
      'template' => 'CRM/SamlAuth/SsoLoginButton.tpl',
    ]);
  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Add SAML settings to the admin menu
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu/
 */
function saml_auth_civicrm_navigationMenu(&$menu): void {
  _saml_auth_civix_insert_navigation_menu($menu, 'Administer/Users and Permissions', [
    'label' => E::ts('SAML Authentication'),
    'name' => 'saml_authentication_settings',
    'url' => 'civicrm/admin/saml',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _saml_auth_civix_navigationMenu($menu);
}
