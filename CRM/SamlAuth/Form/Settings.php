<?php
declare(strict_types = 1);

use CRM_SamlAuth_ExtensionUtil as E;
use BlackBrickSoftware\CiviCRMSamlAuth\Service\ConfigProvider;

/**
 * Admin UI for saml_auth_* settings.
 *
 * All fields are backed by settings/saml_auth.setting.php. Fields whose
 * env var (CIVICRM_SAML_AUTH_*) is set are frozen by
 * BlackBrickSoftware\CiviCRMSamlAuth\Subscriber\SettingsFormSubscriber; secret fields are
 * masked before freezing.
 */
class CRM_SamlAuth_Form_Settings extends CRM_Core_Form {

  public function buildQuickForm(): void {
    \CRM_Utils_System::setTitle(E::ts('SAML Authentication Settings'));

    $this->assign('acsUrl', \CRM_Utils_System::url('civicrm/saml/acs', '', TRUE, NULL, FALSE));
    $this->assign('metadataUrl', \CRM_Utils_System::url('civicrm/saml/metadata', '', TRUE, NULL, FALSE));

    $this->add('select', 'saml_auth_mode', E::ts('SAML Login Mode'), self::getModeOptions(), TRUE);
    $this->add('checkbox', 'saml_auth_debug', E::ts('Debug Mode'));

    $this->add('text', 'saml_auth_idp_entity_id', E::ts('IdP Entity ID'),
      ['size' => 60, 'placeholder' => 'http://www.okta.com/exk1234567890']);
    $this->add('text', 'saml_auth_idp_sso_url', E::ts('IdP SSO URL'),
      ['size' => 60, 'placeholder' => 'https://dev.okta.com/.../sso/saml']);
    $this->add('textarea', 'saml_auth_idp_x509_cert', E::ts('IdP X.509 Certificate'),
      ['rows' => 8, 'cols' => 60, 'placeholder' => 'Base64 cert body, no BEGIN/END lines']);

    $this->add('text', 'saml_auth_sp_entity_id', E::ts('SP Entity ID'), ['size' => 60]);
    $this->add('textarea', 'saml_auth_sp_x509_cert', E::ts('SP X.509 Certificate'),
      ['rows' => 8, 'cols' => 60, 'placeholder' => 'Base64 cert body, no BEGIN/END lines']);
    $this->add('textarea', 'saml_auth_sp_private_key', E::ts('SP Private Key'),
      ['rows' => 8, 'cols' => 60, 'placeholder' => 'PKCS#8 private key body, no BEGIN/END lines']);
    $this->add('checkbox', 'saml_auth_sign_requests', E::ts('Sign AuthnRequests'));

    $this->add('select', 'saml_auth_match_field', E::ts('User Match Field'), self::getMatchFieldOptions(), TRUE);
    $this->add('checkbox', 'saml_auth_provisioning_enabled', E::ts('Auto-provision Users'));

    $this->add('text', 'saml_auth_attr_username', E::ts('SAML attribute: username'), ['size' => 40]);
    $this->add('text', 'saml_auth_attr_email', E::ts('SAML attribute: email'), ['size' => 40]);
    $this->add('text', 'saml_auth_attr_first_name', E::ts('SAML attribute: first name'), ['size' => 40]);
    $this->add('text', 'saml_auth_attr_last_name', E::ts('SAML attribute: last name'), ['size' => 40]);
    $this->add('text', 'saml_auth_attr_roles', E::ts('SAML attribute: roles'),
      ['size' => 40, 'placeholder' => 'roles or groups']);

    $this->add('textarea', 'saml_auth_relaystate_allowlist', E::ts('RelayState Allowlist'),
      ['rows' => 3, 'cols' => 60, 'placeholder' => 'One URL prefix per line']);

    $this->addButtons([
      ['type' => 'submit', 'name' => E::ts('Save'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);

    $defaults = [];
    foreach ($this->getSettingNames() as $name) {
      $defaults[$name] = \Civi::settings()->get($name);
    }
    $this->setDefaults($defaults);

    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess(): void {
    $values = $this->exportValues();
    $config = \Civi::service(ConfigProvider::class);
    $settings = \Civi::settings();
    $ignored = [];

    foreach ($this->getSettingNames() as $name) {
      // Never overwrite an env-managed value.
      if ($config->isOverridden($name)) {
        $ignored[] = $name;
        continue;
      }
      $settings->set($name, $values[$name] ?? NULL);
    }

    if ($ignored !== []) {
      \CRM_Core_Session::setStatus(
        E::ts('%1 env-managed setting(s) were left unchanged.', [1 => count($ignored)]),
        E::ts('Note'),
        'info'
      );
    }

    \CRM_Core_Session::setStatus(E::ts('SAML Authentication settings have been saved.'), E::ts('Saved'), 'success');
    parent::postProcess();
  }

  public static function getModeOptions(): array {
    return [
      ConfigProvider::MODE_DISABLED => E::ts('Disabled — password login only'),
      ConfigProvider::MODE_OPTIONAL => E::ts('Optional — password login + SSO button'),
      ConfigProvider::MODE_REQUIRED => E::ts('Required — SSO only (password form hidden, auto-redirect)'),
    ];
  }

  public static function getMatchFieldOptions(): array {
    return [
      ConfigProvider::MATCH_EMAIL => E::ts('Email Address'),
      ConfigProvider::MATCH_USERNAME => E::ts('Username'),
    ];
  }

  public function getRenderableElementNames(): array {
    $elementNames = [];
    foreach ($this->_elements as $element) {
      if (!empty($element->getLabel())) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  private function getSettingNames(): array {
    return [
      'saml_auth_mode',
      'saml_auth_debug',
      'saml_auth_idp_entity_id',
      'saml_auth_idp_sso_url',
      'saml_auth_idp_x509_cert',
      'saml_auth_sp_entity_id',
      'saml_auth_sp_x509_cert',
      'saml_auth_sp_private_key',
      'saml_auth_sign_requests',
      'saml_auth_match_field',
      'saml_auth_provisioning_enabled',
      'saml_auth_attr_username',
      'saml_auth_attr_email',
      'saml_auth_attr_first_name',
      'saml_auth_attr_last_name',
      'saml_auth_attr_roles',
      'saml_auth_relaystate_allowlist',
    ];
  }

}
