<?php

use CRM_SamlAuth_ExtensionUtil as E;

/**
 * Form controller class for SAML Authentication Settings
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_SamlAuth_Form_Settings extends CRM_Core_Form {

  /**
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(): void {
    CRM_Utils_System::setTitle(E::ts('SAML Authentication Settings'));

    // Add documentation
    $this->assign('setupInstructions', $this->getSetupInstructions());
    $this->assign('testingInstructions', $this->getTestingInstructions());

    // ===== GENERAL SETTINGS =====
    $this->add(
      'checkbox',
      'saml_auth_enabled',
      E::ts('Enable SAML Authentication'),
      [],
      FALSE,
      ['onclick' => 'CRM.$(\'#saml-settings-sections\').toggle(this.checked);']
    );

    $this->add(
      'checkbox',
      'saml_auth_debug',
      E::ts('Enable Debug Mode')
    );

    // ===== IDENTITY PROVIDER (IDP) SETTINGS =====
    $this->add(
      'text',
      'saml_auth_idp_entity_id',
      E::ts('IdP Entity ID'),
      ['size' => 60, 'placeholder' => 'http://www.okta.com/exk1234567890'],
      TRUE
    );

    $this->add(
      'text',
      'saml_auth_idp_sso_url',
      E::ts('IdP SSO URL'),
      ['size' => 60, 'placeholder' => 'https://dev-123456.okta.com/app/dev-123456_civicrm_1/exk1234567890/sso/saml'],
      TRUE
    );

    $this->add(
      'text',
      'saml_auth_idp_slo_url',
      E::ts('IdP SLO URL (Optional)'),
      ['size' => 60, 'placeholder' => 'https://dev-123456.okta.com/app/dev-123456_civicrm_1/exk1234567890/slo/saml']
    );

    $this->add(
      'textarea',
      'saml_auth_idp_x509_cert',
      E::ts('IdP X.509 Certificate'),
      ['rows' => 8, 'cols' => 60, 'placeholder' => 'Paste certificate content without BEGIN/END lines'],
      TRUE
    );

    // ===== SERVICE PROVIDER (SP) SETTINGS =====
    $baseUrl = \CRM_Utils_System::url('', '', TRUE, NULL, FALSE);
    $baseUrl = rtrim($baseUrl, '/');

    $this->add(
      'text',
      'saml_auth_sp_entity_id',
      E::ts('SP Entity ID'),
      ['size' => 60, 'placeholder' => $baseUrl],
      TRUE
    );

    // Display ACS and SLS URLs (read-only info)
    $acsUrl = \CRM_Utils_System::url('civicrm/saml/acs', '', TRUE, NULL, FALSE);
    $slsUrl = \CRM_Utils_System::url('civicrm/saml/sls', '', TRUE, NULL, FALSE);
    $this->assign('acsUrl', $acsUrl);
    $this->assign('slsUrl', $slsUrl);

    // ===== USER PROVISIONING SETTINGS =====
    $this->add(
      'select',
      'saml_auth_user_lookup_field',
      E::ts('User Lookup Field'),
      $this->getUserLookupOptions(),
      TRUE
    );

    $this->add(
      'checkbox',
      'saml_auth_enable_provisioning',
      E::ts('Enable Auto-Provisioning')
    );

    $this->add(
      'text',
      'saml_auth_role_attribute',
      E::ts('Role Attribute Name'),
      ['size' => 40, 'placeholder' => 'roles or groups']
    );

    // ===== SECURITY SETTINGS =====
    $this->add(
      'checkbox',
      'saml_auth_enforce_sso',
      E::ts('Enforce SSO Only')
    );

    $this->add(
      'text',
      'saml_auth_bypass_key',
      E::ts('Emergency Bypass Key'),
      ['size' => 40, 'placeholder' => 'Generate random 32+ character string']
    );

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Save'),
        'isDefault' => TRUE,
      ],
      [
        'type' => 'cancel',
        'name' => E::ts('Cancel'),
      ],
    ]);

    // Set defaults from existing settings
    $defaults = [];
    foreach ($this->getSettingNames() as $setting) {
      $defaults[$setting] = \Civi::settings()->get($setting);
    }
    $this->setDefaults($defaults);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess(): void {
    $values = $this->exportValues();

    // Save all settings
    foreach ($this->getSettingNames() as $setting) {
      $value = $values[$setting] ?? NULL;
      \Civi::settings()->set($setting, $value);
    }

    CRM_Core_Session::setStatus(
      E::ts('SAML Authentication settings have been saved.'),
      E::ts('Saved'),
      'success'
    );

    parent::postProcess();
  }

  /**
   * Get list of setting names managed by this form
   *
   * @return array
   */
  private function getSettingNames(): array {
    return [
      'saml_auth_enabled',
      'saml_auth_debug',
      'saml_auth_idp_entity_id',
      'saml_auth_idp_sso_url',
      'saml_auth_idp_slo_url',
      'saml_auth_idp_x509_cert',
      'saml_auth_sp_entity_id',
      'saml_auth_user_lookup_field',
      'saml_auth_enable_provisioning',
      'saml_auth_role_attribute',
      'saml_auth_enforce_sso',
      'saml_auth_bypass_key',
    ];
  }

  /**
   * Get user lookup field options
   *
   * @return array
   */
  public static function getUserLookupOptions(): array {
    return [
      'email' => E::ts('Email Address'),
      'username' => E::ts('Username'),
    ];
  }

  /**
   * Get setup instructions
   *
   * @return string
   */
  private function getSetupInstructions(): string {
    // Get ACS URL for display in instructions
    $acsUrl = \CRM_Utils_System::url('civicrm/saml/acs', '', TRUE, NULL, FALSE);
    $baseUrl = \CRM_Utils_System::url('', '', TRUE, NULL, FALSE);
    $baseUrl = rtrim($baseUrl, '/');

    return '
<h3>Setup Instructions</h3>
<ol>
  <li><strong>Configure Your Identity Provider (Okta/Azure AD/etc.)</strong>
    <ul>
      <li>Create a new SAML application in your IdP</li>
      <li>Set <strong>Single Sign-On URL (ACS URL)</strong> to: <code>' . htmlspecialchars($acsUrl) . '</code></li>
      <li>Set <strong>Audience URI (SP Entity ID)</strong> to: <code>' . htmlspecialchars($baseUrl) . '</code></li>
      <li>Set <strong>Name ID Format</strong> to:
        <ul>
          <li>"<strong>EmailAddress</strong>" if using email lookup (recommended)</li>
          <li>"<strong>Persistent</strong>" or "<strong>Unspecified</strong>" if using username lookup</li>
        </ul>
      </li>
      <li>Standard SAML attributes (automatically mapped):
        <ul>
          <li><code>http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname</code> → First Name</li>
          <li><code>http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname</code> → Last Name</li>
        </ul>
      </li>
      <li>Optional: Add custom role attribute if you want role assignment (configure attribute name below)</li>
      <li>Copy the IdP metadata (Entity ID, SSO URL, and X.509 certificate)</li>
    </ul>
  </li>
  <li><strong>Configure This Extension</strong>
    <ul>
      <li>Paste your IdP metadata into the Identity Provider section below</li>
      <li>Set your Service Provider Entity ID (default: <code>' . htmlspecialchars($baseUrl) . '</code>)</li>
      <li>Choose how to look up users (email or username)</li>
      <li>Enable auto-provisioning if you want new users created automatically</li>
      <li>Optionally configure role assignment from SAML attributes</li>
    </ul>
  </li>
  <li><strong>Test the Configuration</strong>
    <ul>
      <li>Enable SAML Authentication</li>
      <li>Enable Debug Mode</li>
      <li>Save settings</li>
      <li>Log out and test SSO login (see Testing section below)</li>
      <li>Check CiviCRM logs for detailed debugging if issues occur</li>
    </ul>
  </li>
  <li><strong>Enable Enforcement (Optional)</strong>
    <ul>
      <li>Once SSO is working, you can enable "Enforce SSO Only" to block traditional logins</li>
      <li>Set an emergency bypass key before enabling enforcement</li>
      <li>Test the bypass works: add ?bypass=YOUR_KEY to the login URL</li>
    </ul>
  </li>
</ol>
';
  }

  /**
   * Get testing instructions
   *
   * @return string
   */
  private function getTestingInstructions(): string {
    $loginUrl = \CRM_Utils_System::url('civicrm/user', '', TRUE, NULL, FALSE);
    $samlLoginUrl = \CRM_Utils_System::url('civicrm/saml/login', '', TRUE, NULL, FALSE);

    return '
<h3>Testing Your Configuration</h3>
<ol>
  <li><strong>Initial Test</strong>
    <ul>
      <li>Ensure "Enable SAML Authentication" is checked and "Enable Debug Mode" is enabled</li>
      <li>Save settings</li>
      <li>Log out of CiviCRM</li>
      <li>Visit the login page: <code>' . htmlspecialchars($loginUrl) . '</code></li>
      <li>You should see a "Login with SSO" button</li>
    </ul>
  </li>
  <li><strong>SSO Login Test</strong>
    <ul>
      <li>Click "Login with SSO" (or visit <code>' . htmlspecialchars($samlLoginUrl) . '</code> directly)</li>
      <li>You should be redirected to your IdP (Okta, Azure AD, etc.)</li>
      <li>Complete authentication at your IdP</li>
      <li>You should be redirected back to CiviCRM and logged in</li>
    </ul>
  </li>
  <li><strong>Check Logs</strong>
    <ul>
      <li>If login fails, check CiviCRM logs for SAML debugging information</li>
      <li>Look for messages starting with "SAML:"</li>
      <li>Common issues: incorrect certificate, mismatched entity IDs, wrong ACS URL in IdP</li>
    </ul>
  </li>
  <li><strong>Test User Provisioning</strong>
    <ul>
      <li>If auto-provisioning is enabled, test with a user that doesn\'t exist in CiviCRM</li>
      <li>They should be automatically created on first login</li>
      <li>If disabled, they should see an error message</li>
    </ul>
  </li>
  <li><strong>Test Role Assignment (if configured)</strong>
    <ul>
      <li>Set a role attribute name (e.g., "roles" or "groups")</li>
      <li>Ensure your IdP sends this attribute with matching CiviCRM role names</li>
      <li>Login and verify roles are assigned correctly in Users and Permissions</li>
    </ul>
  </li>
  <li><strong>Test Enforcement and Bypass</strong>
    <ul>
      <li>Set an emergency bypass key (random 32+ character string)</li>
      <li>Enable "Enforce SSO Only"</li>
      <li>Save and log out</li>
      <li>Normal login should redirect to SSO automatically</li>
      <li>Test bypass: <code>' . htmlspecialchars($loginUrl) . '?bypass=YOUR_KEY</code></li>
      <li>Bypass should allow traditional username/password login</li>
    </ul>
  </li>
</ol>
<p><strong>Important:</strong> Keep debug mode enabled until you\'ve verified everything works. Disable it in production for better performance.</p>
';
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames(): array {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = [];
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
