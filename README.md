# SAML Authentication for CiviCRM Standalone

SAML2-based Single Sign-On authentication extension for CiviCRM Standalone. Enables seamless integration with enterprise identity providers like Okta, Azure AD, Google Workspace, and other SAML2-compliant IdPs.

This extension provides secure SSO authentication with optional enforcement mode that blocks traditional username/password logins, while maintaining an emergency bypass mechanism for disaster recovery scenarios.

This is an [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/), licensed under [AGPL-3.0](LICENSE.txt).

## Features

- **SAML2 SSO Authentication**: Authenticate users via your existing identity provider
- **Multiple IdP Support**: Works with Okta, Azure AD, Google Workspace, and other SAML2 providers
- **SSO Enforcement**: Optionally block traditional logins and require SSO authentication
- **Emergency Bypass**: Secret key bypass mechanism for emergency access if IdP is unavailable
- **Auto-Provisioning**: Automatically creates CiviCRM contacts and users from SAML attributes
- **Debug Mode**: Detailed logging for troubleshooting SAML flows
- **Secure by Default**: Uses SHA-256 signatures and follows SAML2 security best practices

## Requirements

- CiviCRM Standalone (6.5+)
- PHP 8.1+
- Access to your Identity Provider's SAML2 configuration
- Composer (for installing dependencies)

## Installation

1. Download or clone this extension into your CiviCRM extensions directory (`web/ext/`)
2. Install dependencies:
   ```bash
   cd web/ext/saml_auth
   composer install
   ```
3. Enable the extension in CiviCRM:
   - Navigate to **Administer** > **System Settings** > **Extensions**
   - Find "SAML Authentication" and click **Enable**

## Configuration

### 1. Configure Your Identity Provider

In your IdP (e.g., Okta), create a new SAML application with these settings:

- **Single Sign-On URL (ACS URL)**: `https://your-site.org/civicrm/saml/acs`
- **Audience URI (SP Entity ID)**: `https://your-site.org` (or your custom entity ID)
- **Name ID Format**: Email Address
- **Attribute Statements** (optional):
  - `firstName` → user.firstName
  - `lastName` → user.lastName

### 2. Configure the Extension in CiviCRM

Navigate to **Administer** > **System Settings** > **SAML Authentication**:

**Identity Provider Settings:**
- **Identity Provider Entity ID**: The Issuer/Entity ID from your IdP
- **Single Sign-On URL**: The SSO URL from your IdP
- **Single Logout URL**: (Optional) The SLO URL from your IdP
- **X.509 Certificate**: Paste the public certificate from your IdP (without `-----BEGIN CERTIFICATE-----` headers)

**Service Provider Settings:**
- **Service Provider Entity ID**: Your CiviCRM entity ID (usually your base URL)

**Security Settings:**
- **Enable SAML Authentication**: Check to enable SSO
- **Enforce SSO Only**: Check to block traditional username/password logins
- **Emergency Bypass Key**: Set a secret key for emergency access (e.g., a random 32-character string)
- **Enable Debug Mode**: Enable for detailed SAML debugging in logs

### 3. Test the Configuration

1. Log out of CiviCRM
2. Navigate to the login page - you should see a "Login with SSO" button
3. Click the button and complete authentication with your IdP
4. You should be logged into CiviCRM with your account auto-created

## Emergency Bypass

If SSO enforcement is enabled but your IdP is unavailable, you can bypass SSO using the emergency key:

```
https://your-site.org/civicrm/user?bypass=YOUR_SECRET_KEY
```

This will allow you to use traditional username/password login temporarily.

**Security Note**: Keep your bypass key secret and change it regularly. Never share it in documentation or commit it to version control.

## User Provisioning

When a user authenticates via SAML for the first time:

1. The extension searches for an existing contact with the user's email address
2. If found, it associates the SAML login with that contact
3. If not found, it creates a new contact using SAML attributes:
   - First Name (from `firstName` or givenname claim)
   - Last Name (from `lastName` or surname claim)
   - Email Address (from NameID)
4. A CiviCRM Standalone user account is created and linked to the contact

## Troubleshooting

### Enable Debug Mode

1. Go to **Administer** > **System Settings** > **SAML Authentication**
2. Check "Enable Debug Mode"
3. Save settings
4. Check CiviCRM logs for detailed SAML debugging information

### Common Issues

**"SAML authentication failed"**
- Verify your IdP Entity ID and SSO URL are correct
- Ensure the X.509 certificate is properly formatted (no headers/footers)
- Check that your IdP is sending assertions to the correct ACS URL

**"Invalid email format in SAML response"**
- Verify your IdP is configured to send email as the NameID
- Check that NameID Format is set to "Email Address"

**"User redirected back to login page"**
- Enable debug mode and check logs for specific errors
- Verify ACS URL in IdP matches your CiviCRM URL exactly

## Security Considerations

1. **Always use HTTPS** in production - SAML requires encrypted transport
2. **Protect your bypass key** - treat it like a root password
3. **Review SAML assertions** - ensure your IdP only sends expected attributes
4. **Monitor authentication logs** - watch for suspicious login patterns
5. **Rotate certificates** - update IdP certificates before they expire

## Development

Built with:
- [OneLogin PHP-SAML](https://github.com/SAML-Toolkits/php-saml) - SAML2 library
- [civix](https://github.com/totten/civix/) - CiviCRM extension development tool

### File Structure

```
saml_auth/
├── CRM/SamlAuth/          # Form controllers and pages
│   ├── Form/
│   │   └── Settings.php   # Settings form
│   └── Page/
│       ├── Login.php      # Initiates SSO flow
│       └── Acs.php        # Handles SAML response
├── src/                   # PSR-4 autoloaded classes
│   └── SamlService.php    # Core SAML service
├── settings/              # Extension settings metadata
├── templates/             # Smarty templates
├── xml/Menu/              # Menu definitions
├── composer.json          # PHP dependencies
├── info.xml               # Extension metadata
└── saml_auth.php          # Hook implementations
```

## Support

- **Issues**: https://github.com/blackbricksoftware/civicrm-saml-auth/issues
- **Documentation**: https://github.com/blackbricksoftware/civicrm-saml-auth

## License

This extension is licensed under [AGPL-3.0](LICENSE.txt).

## Credits

Developed by [Black Brick Software](https://blackbrick.software) for the CiviCRM community.
