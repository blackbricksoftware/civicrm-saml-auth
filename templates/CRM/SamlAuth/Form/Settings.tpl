{* SAML Authentication Settings Form *}

<div class="crm-block crm-form-block crm-saml-auth-settings-form-block">

  {if $samlAuthOverridden and $samlAuthOverridden|@count}
    <div class="messages status no-popup">
      <p><strong>{ts}Environment-managed settings{/ts}</strong></p>
      <p>{ts 1=$samlAuthOverridden|@count}%1 field(s) below are set via environment variables (CIVICRM_SAML_AUTH_*) or civicrm.settings.php. They are read-only here; changing them requires updating the environment and redeploying. Secret fields are masked.{/ts}</p>
    </div>
  {/if}

  <div class="help">
    <h3>{ts}Endpoints{/ts}</h3>
    <p>{ts}Configure your IdP with these URLs:{/ts}</p>
    <ul>
      <li><strong>{ts}Assertion Consumer Service (ACS){/ts}:</strong> <code>{$acsUrl}</code></li>
      <li><strong>{ts}SP Metadata{/ts}:</strong> <code>{$metadataUrl}</code> &mdash; {ts}most IdPs can import SP configuration directly from this URL.{/ts}</li>
    </ul>
  </div>

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="top"}
  </div>

  {foreach from=$elementNames item=elementName}
    <div class="crm-section crm-section-{$elementName}">
      <div class="label">{$form.$elementName.label}</div>
      <div class="content">
        {$form.$elementName.html}
      </div>
      <div class="clear"></div>
    </div>
  {/foreach}

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>

</div>
