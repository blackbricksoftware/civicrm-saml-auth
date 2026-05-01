{* SSO Login Button — placed below the Angular login form via JS.
   The .saml-sso-section--fallback block is rendered hidden and only shown
   if the Angular form never appears (e.g. JS disabled, render error). *}
{if $samlLoginUrl}
<div class="crm-section saml-sso-section saml-sso-section--fallback" style="display:none;">
  <a href="{$samlLoginUrl}" class="btn crm-button saml-sso-button"
     style="justify-self:end;background:#fff;color:#2D0A4E;border:1px solid #2D0A4E;padding:0.5rem 1rem;text-decoration:none;">
    <i class="crm-i fa-sign-in"></i> Login with SSO
  </a>
</div>
<script>
(function () {
  if (window.__samlSsoButtonInjected) return;
  window.__samlSsoButtonInjected = true;
  var url = "{$samlLoginUrl|escape:'javascript'}";
  var DBG = '[saml-auth]';

  function inject() {
    if (document.querySelector('.saml-sso-button:not(.saml-sso-section--fallback .saml-sso-button)')) {
      return true; // already injected
    }
    // Target the form-state .crm-login (the one without -loading/-already-logged-in modifiers).
    var form = document.querySelector('.crm-login:not(.crm-login-loading):not(.crm-login-already-logged-in) form');
    if (!form) return false;
    var wrap = document.createElement('div');
    wrap.className = 'crm-section saml-sso-section';
    wrap.style.cssText = 'display:grid;margin-top:1rem;';
    var a = document.createElement('a');
    a.href = url;
    a.className = 'btn crm-button saml-sso-button';
    a.style.cssText = 'justify-self:end;background:#fff;color:#2D0A4E;border:1px solid #2D0A4E;padding:0.5rem 1rem;text-decoration:none;';
    a.innerHTML = '<i class="crm-i fa-sign-in"></i> Login with SSO';
    wrap.appendChild(a);
    // Insert as a sibling of <form>, inside the form-state .crm-login div.
    form.parentNode.insertBefore(wrap, form.nextSibling);
    console.log(DBG, 'SSO button injected');
    return true;
  }

  // First attempt — runs as soon as the script tag executes.
  if (inject()) return;

  // Watch for the Angular form to render.
  var settled = false;
  var observer = new MutationObserver(function () {
    if (settled) return;
    if (inject()) {
      settled = true;
      observer.disconnect();
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });

  // Final timeout — reveal the fallback if Angular never finishes.
  setTimeout(function () {
    if (settled) return;
    observer.disconnect();
    if (!document.querySelector('.saml-sso-section:not(.saml-sso-section--fallback)')) {
      console.warn(DBG, 'Angular form never rendered after 5s — showing fallback');
      var fb = document.querySelector('.saml-sso-section--fallback');
      if (fb) fb.style.display = 'grid';
    }
  }, 5000);
})();
</script>
{/if}
