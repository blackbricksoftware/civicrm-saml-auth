{* SAML Authentication Settings Form *}

<div class="crm-block crm-form-block crm-saml-auth-settings-form-block">

  {* Setup Instructions *}
  <div class="help">
    {$setupInstructions}
  </div>

  {* Submit Buttons at Top *}
  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="top"}
  </div>

  {* General Settings Section *}
  <div class="crm-section crm-section-general">
    <h3>General Settings</h3>
    <div class="crm-section">
      <div class="label">{$form.saml_auth_enabled.label}</div>
      <div class="content">
        {$form.saml_auth_enabled.html}
        <p class="description">Master switch to enable/disable SAML authentication. When disabled, traditional login works normally.</p>
      </div>
      <div class="clear"></div>
    </div>

    <div class="crm-section">
      <div class="label">{$form.saml_auth_debug.label}</div>
      <div class="content">
        {$form.saml_auth_debug.html}
        <p class="description">Enable detailed SAML debugging in CiviCRM logs. Recommended during initial setup and testing.</p>
      </div>
      <div class="clear"></div>
    </div>
  </div>

  {* Identity Provider Settings Section *}
  <div class="crm-section crm-section-idp" id="saml-settings-sections">
    <h3>Identity Provider (IdP) Settings</h3>
    <p class="description">Configuration from your SAML identity provider (Okta, Azure AD, Google Workspace, etc.)</p>

    <div class="crm-section">
      <div class="label">{$form.saml_auth_idp_entity_id.label}</div>
      <div class="content">
        {$form.saml_auth_idp_entity_id.html}
        <p class="description">The unique identifier (Issuer) of your identity provider. Found in your IdP's SAML metadata.</p>
      </div>
      <div class="clear"></div>
    </div>

    <div class="crm-section">
      <div class="label">{$form.saml_auth_idp_sso_url.label}</div>
      <div class="content">
        {$form.saml_auth_idp_sso_url.html}
        <p class="description">The URL where users are sent to authenticate. Found in your IdP's SAML metadata.</p>
      </div>
      <div class="clear"></div>
    </div>

    <div class="crm-section">
      <div class="label">{$form.saml_auth_idp_slo_url.label}</div>
      <div class="content">
        {$form.saml_auth_idp_slo_url.html}
        <p class="description">Optional: URL for Single Logout. Leave empty if not supported by your IdP.</p>
      </div>
      <div class="clear"></div>
    </div>

    <div class="crm-section">
      <div class="label">{$form.saml_auth_idp_x509_cert.label}</div>
      <div class="content">
        {$form.saml_auth_idp_x509_cert.html}
        <p class="description">Paste the X.509 certificate from your IdP. Remove "-----BEGIN CERTIFICATE-----" and "-----END CERTIFICATE-----" lines if present.</p>
      </div>
      <div class="clear"></div>
    </div>
  </div>

  {* Service Provider Settings Section *}
  <div class="crm-section crm-section-sp" id="saml-sp-section">
    <h3>Service Provider (SP) Settings</h3>
    <p class="description">Your CiviCRM configuration as the SAML service provider.</p>

    <div class="crm-section">
      <div class="label">{$form.saml_auth_sp_entity_id.label}</div>
      <div class="content">
        {$form.saml_auth_sp_entity_id.html}
        <p class="description">Unique identifier for this CiviCRM installation. Usually your base URL. Must match what's configured in your IdP.</p>
      </div>
      <div class="clear"></div>
    </div>

    <div class="crm-section">
      <div class="label"><strong>ACS URL (Assertion Consumer Service)</strong></div>
      <div class="content">
        <input type="text" readonly value="{$acsUrl}" size="60" style="background-color: #f5f5f5;" onclick="this.select();" />
        <p class="description">Copy this URL and configure it in your IdP as the "Single Sign-On URL" or "ACS URL".</p>
      </div>
      <div class="clear"></div>
    </div>

    <div class="crm-section">
      <div class="label"><strong>SLS URL (Single Logout Service)</strong></div>
      <div class="content">
        <input type="text" readonly value="{$slsUrl}" size="60" style="background-color: #f5f5f5;" onclick="this.select();" />
        <p class="description">Optional: Configure this in your IdP if you want to support Single Logout.</p>
      </div>
      <div class="clear"></div>
    </div>
  </div>

  {* User Provisioning Settings Section *}
  <div class="crm-section crm-section-provisioning" id="saml-provisioning-section">
    <h3>User Provisioning Settings</h3>
    <p class="description">Control how users are looked up and created when they authenticate via SAML.</p>

    <div class="crm-section">
      <div class="label">{$form.saml_auth_user_lookup_field.label}</div>
      <div class="content">
        {$form.saml_auth_user_lookup_field.html}
        <p class="description">Field to use for finding existing users. "Email" uses SAML NameID as email, "Username" uses NameID as username.</p>
      </div>
      <div class="clear"></div>
    </div>

    <div class="crm-section">
      <div class="label">{$form.saml_auth_enable_provisioning.label}</div>
      <div class="content">
        {$form.saml_auth_enable_provisioning.html}
        <p class="description">When enabled, new users are automatically created on first login. When disabled, only existing users can login via SSO.</p>
      </div>
      <div class="clear"></div>
    </div>

    <div class="crm-section">
      <div class="label">{$form.saml_auth_role_attribute.label}</div>
      <div class="content">
        {$form.saml_auth_role_attribute.html}
        <p class="description">Optional: Name of SAML attribute containing user roles (e.g., "roles", "groups"). Role names from IdP must match CiviCRM role names exactly. Leave empty to disable automatic role assignment.</p>
      </div>
      <div class="clear"></div>
    </div>
  </div>

  {* Security Settings Section *}
  <div class="crm-section crm-section-security" id="saml-security-section">
    <h3>Security Settings</h3>
    <p class="description">Control SSO enforcement and emergency access.</p>

    <div class="crm-section">
      <div class="label">{$form.saml_auth_enforce_sso.label}</div>
      <div class="content">
        {$form.saml_auth_enforce_sso.html}
        <p class="description"><strong>Warning:</strong> When enabled, traditional username/password login is blocked and users must authenticate via SSO. Set an emergency bypass key before enabling this!</p>
      </div>
      <div class="clear"></div>
    </div>

    <div class="crm-section">
      <div class="label">{$form.saml_auth_bypass_key.label}</div>
      <div class="content">
        {$form.saml_auth_bypass_key.html}
        <p class="description">Secret key to bypass SSO enforcement (add ?bypass=YOUR_KEY to login URL). Generate a random 32+ character string. Keep this secure!</p>
      </div>
      <div class="clear"></div>
    </div>
  </div>

  {* Testing Instructions *}
  <div class="help">
    {$testingInstructions}
  </div>

  {* Submit Buttons at Bottom *}
  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>

</div>

{* JavaScript to show/hide sections based on enabled checkbox *}
{literal}
<script type="text/javascript">
  CRM.$(function($) {
    // Show/hide settings sections based on enabled checkbox
    function toggleSections() {
      var isEnabled = $('#saml_auth_enabled').is(':checked');
      $('#saml-settings-sections, #saml-sp-section, #saml-provisioning-section, #saml-security-section').toggle(isEnabled);
    }

    // Initial state
    toggleSections();

    // Toggle on change
    $('#saml_auth_enabled').on('change', toggleSections);
  });
</script>
{/literal}
