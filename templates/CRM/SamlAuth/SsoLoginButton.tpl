{* SSO Login Button Template *}
{if $samlLoginUrl}
<div class="crm-section saml-sso-section" style="margin: 20px 0; padding: 20px; border: 1px solid #ddd; border-radius: 4px; background-color: #f9f9f9;">
  <div class="label"></div>
  <div class="content">
    <div style="text-align: center;">
      <p style="margin-bottom: 15px; font-weight: bold;">Single Sign-On</p>
      <a href="{$samlLoginUrl}" class="button" style="display: inline-block; padding: 10px 20px; background-color: #0071bd; color: white; text-decoration: none; border-radius: 4px;">
        <i class="crm-i fa-sign-in"></i> Login with SSO
      </a>
    </div>
  </div>
  <div class="clear"></div>
</div>
{/if}
