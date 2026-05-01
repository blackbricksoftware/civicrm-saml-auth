# SAML Authentication for CiviCRM Standalone

SAML2 SSO for CiviCRM Standalone. Works with any SAML2 IdP (Okta, Azure AD,
Google Workspace, Keycloak, …). Designed for containerized deployments:
every setting can be pinned via an environment variable, in which case the
UI shows the value as read-only (and secrets as masked).

Licensed under [AGPL-3.0](LICENSE.txt).

## Login modes

A single setting — `saml_auth_mode` / `CIVICRM_SAML_AUTH_MODE` — controls
everything:

| Mode | Password login | SSO button on login page | Auto-redirect to IdP |
|---|---|---|---|
| `disabled` | ✅ | ❌ | ❌ |
| `optional` | ✅ | ✅ | ❌ |
| `required` | ❌ (hidden) | — | ✅ |

Both **SP-initiated** (user clicks SSO button, or visits the login page in
`required` mode) and **IdP-initiated** (IdP POSTs an unsolicited
SAMLResponse to the ACS) are supported.

## Endpoints

| Purpose | URL |
|---|---|
| ACS (Assertion Consumer Service) | `https://<site>/civicrm/saml/acs` |
| SP Metadata | `https://<site>/civicrm/saml/metadata` |
| SP-init initiator | `https://<site>/civicrm/saml/login` |
| Admin UI | `https://<site>/civicrm/admin/saml` |

Most IdPs can import SP configuration from the metadata URL — no need to
copy/paste entity IDs, ACS URLs, or SP certs by hand.

## Installation

1. Drop the extension into your `ext/` directory.
2. `cd ext/saml_auth && composer install` (installs `onelogin/php-saml`).
3. Enable: `cv en saml_auth` or Administer → System Settings → Extensions.

## Configuration

Every setting has an equivalent environment variable. Env wins over DB, and
over any value set in the UI. `civicrm.settings.php`'s `$civicrm_setting`
mandatory layer is equally honoured.

### Environment variables

| Env var | Setting | Notes |
|---|---|---|
| `CIVICRM_SAML_AUTH_MODE` | `saml_auth_mode` | `disabled` \| `optional` \| `required` |
| `CIVICRM_SAML_AUTH_IDP_ENTITY_ID` | `saml_auth_idp_entity_id` | |
| `CIVICRM_SAML_AUTH_IDP_SSO_URL` | `saml_auth_idp_sso_url` | |
| `CIVICRM_SAML_AUTH_IDP_X509_CERT` | `saml_auth_idp_x509_cert` | Body only, no BEGIN/END |
| `CIVICRM_SAML_AUTH_SP_ENTITY_ID` | `saml_auth_sp_entity_id` | Defaults to base URL |
| `CIVICRM_SAML_AUTH_SP_X509_CERT` | `saml_auth_sp_x509_cert` | Required if signing AuthnRequests |
| `CIVICRM_SAML_AUTH_SP_PRIVATE_KEY` | `saml_auth_sp_private_key` | **Secret — set via env, not the UI** |
| `CIVICRM_SAML_AUTH_SIGN_REQUESTS` | `saml_auth_sign_requests` | Requires SP cert + key |
| `CIVICRM_SAML_AUTH_MATCH_FIELD` | `saml_auth_match_field` | `username` \| `email` |
| `CIVICRM_SAML_AUTH_PROVISIONING_ENABLED` | `saml_auth_provisioning_enabled` | |
| `CIVICRM_SAML_AUTH_ATTR_USERNAME` | `saml_auth_attr_username` | Blank → fall back to NameID |
| `CIVICRM_SAML_AUTH_ATTR_EMAIL` | `saml_auth_attr_email` | Blank → fall back to NameID |
| `CIVICRM_SAML_AUTH_ATTR_FIRST_NAME` | `saml_auth_attr_first_name` | Blank → skip |
| `CIVICRM_SAML_AUTH_ATTR_LAST_NAME` | `saml_auth_attr_last_name` | Blank → skip |
| `CIVICRM_SAML_AUTH_ATTR_ROLES` | `saml_auth_attr_roles` | Blank → skip per-login role sync |
| `CIVICRM_SAML_AUTH_DEFAULT_ROLES` | `saml_auth_default_roles` | Comma/newline-separated role names; applied **only at provision time** |
| `CIVICRM_SAML_AUTH_RELAYSTATE_ALLOWLIST` | `saml_auth_relaystate_allowlist` | Newline- or comma-separated URL prefixes |

### User matching & provisioning

- `match_field=email` (default): the SAML email attribute (or NameID, if no
  email attribute is configured) is matched against CiviCRM `User.uf_name`.
- `match_field=username`: the SAML username attribute (or NameID) is
  matched against `User.username`.
- If no existing user matches and `provisioning_enabled=1`, a new Contact
  and User are created. Only the attributes you configure are copied —
  each `ATTR_*` setting is optional. Leave any blank to skip that field.

### Role sync vs. default roles

Two independent knobs cover the two common patterns:

- **IdP-authoritative** — set `ATTR_ROLES` to the SAML attribute that
  carries role names. On every login the user's CiviCRM roles are wiped
  and rewritten from the IdP payload. Manual edits in CiviCRM don't
  survive the next login. Use this when the IdP is the source of truth
  for who has which role.

- **Default-on-provision** — leave `ATTR_ROLES` blank and set
  `DEFAULT_ROLES` to one or more role names (comma- or newline-separated).
  Those roles are applied only when a user is first provisioned via SAML;
  subsequent logins do not touch role assignments. Admins manage roles
  inside CiviCRM after that, and the changes stick.

Both settings can be set together, but the IdP-authoritative path will
overwrite the defaults on the very next login — so it rarely makes sense.

In either case, role names that don't exist in CiviCRM are logged and
skipped; they are never auto-created.

### RelayState allowlist

To prevent open-redirect abuse on the IdP-initiated flow, the ACS will
only honour `RelayState` values that match (are equal to, or begin with
`prefix/`) one of the configured URL prefixes. Empty allowlist ⇒
defaults to just this site's base URL. Anything else ⇒ redirect goes to
`/civicrm/home`.

### Emergency fallback (replaces the old bypass key)

The old `?bypass=<key>` mechanism is gone — it stored a plaintext secret
in the DB and bypassed the standard auth flow. To regain password login
if your IdP is down:

1. `export CIVICRM_SAML_AUTH_MODE=disabled` in the container environment.
2. Restart / redeploy.
3. The password form reappears immediately; password-based auth works
   with no further configuration change.

## Feature modularity (subscriber pattern)

Hooks are wired in `saml_auth.php`'s `hook_civicrm_container()`. Each
feature is a single EventSubscriber class under `Civi\SamlAuth\Subscriber\`.
To disable a feature, comment out its `addSubscriber` line and run
`cv flush`.

```php
$container->findDefinition('dispatcher')
  ->addMethodCall('addSubscriber', [new Definition(LoginFormSubscriber::class, [...])])
  ->addMethodCall('addSubscriber', [new Definition(SettingsFormSubscriber::class, [...])])
  ->addMethodCall('addSubscriber', [new Definition(NavigationMenuSubscriber::class)])
;
```

## Security notes

1. Always use HTTPS. SAML signatures do not protect against
   transport-level tampering of the non-signed parts of the message.
2. Prefer env/`civicrm.settings.php` for the SP private key and IdP cert.
   DB-stored secrets are readable by anyone with admin SQL access.
3. Session IDs are regenerated at the moment of SAML login to prevent
   session fixation.
4. `authnRequestsSigned` and the published SP metadata's KeyDescriptor
   are driven by `saml_auth_sign_requests` + the SP cert/key.
5. Assertions must be signed (`wantAssertionsSigned=TRUE`, non-negotiable).

## Troubleshooting

1. Tail the CiviCRM logs — every SAML step emits a `SAML:` entry. Routine
   flow events log at `debug`/`info`, security-adjacent events
   (RelayState rejection, missing role mapping) at `warning`, and caught
   exceptions at `error`. Whether the lower-severity entries are written
   depends on the host's CiviCRM logger configuration.
2. User-facing failures render as
   `SAML <stage> failed. Please contact an administrator. (Ref: ABCDEF)`.
   Grep `Ref: ABCDEF` in logs to find the matching error entry — full
   exception detail and stack trace are attached via Monolog's `exception`
   context key.
3. `curl -sSf https://<site>/civicrm/saml/metadata | xmllint --noout -` to
   confirm SP metadata is valid.
4. If a setting keeps reverting to an old value, check
   `cv ev 'return Civi::settings()->getMandatory("saml_auth_<key>");'`
   to see if an env var is overriding it.

## Single Logout (SLS)

Deferred in this release. If you need it, file an issue — the ACS flow
is the hard part and it's already done.

## File structure

```
saml_auth/
├── CRM/SamlAuth/
│   ├── Form/Settings.php         admin UI
│   ├── Page/Login.php            SP-init initiator
│   ├── Page/Acs.php              ACS (SP + IdP initiated)
│   ├── Page/Metadata.php         SP metadata
│   └── Upgrader.php              v1→v2 setting migration
├── Civi/SamlAuth/
│   ├── Service/
│   │   ├── ConfigProvider.php    env-aware setting reader
│   │   ├── SamlService.php       auth orchestration
│   │   ├── UserMatcher.php       username/email lookup
│   │   └── RelayStateValidator.php
│   └── Subscriber/
│       ├── LoginFormSubscriber.php
│       ├── SettingsFormSubscriber.php
│       └── NavigationMenuSubscriber.php
├── settings/saml_auth.setting.php  env-loadable metadata
├── templates/CRM/SamlAuth/…
├── xml/Menu/saml_auth.xml
├── composer.json / info.xml
└── saml_auth.php                 hook_civicrm_container + civix stubs
```

## Credits

Developed by [Black Brick Software](https://blackbrick.software).
