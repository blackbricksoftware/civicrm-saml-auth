<?php

use CRM_SamlAuth_ExtensionUtil as E;
use BlackBrickSoftware\CiviCRMSamlAuth\SamlService;

/**
 * SAML Assertion Consumer Service (ACS) - Handles SAML response
 */
class CRM_SamlAuth_Page_Acs extends CRM_Core_Page {

  public function run() {
    try {
      if (!SamlService::isEnabled()) {
        throw new \Exception('SAML authentication is not enabled');
      }

      SamlService::debug('Processing SAML response');

      // Process the SAML response
      $samlData = SamlService::processResponse();

      SamlService::debug('SAML authentication successful', [
        'nameId' => $samlData['nameId'],
      ]);

      // Find or create the user
      $contactId = SamlService::findOrCreateUser($samlData);

      SamlService::debug('User authenticated', ['contact_id' => $contactId]);

      // Get the user record for Standalone
      $user = \Civi\Api4\User::get(FALSE)
        ->addWhere('contact_id', '=', $contactId)
        ->execute()
        ->first();

      if (!$user) {
        // Create a new user record for this contact if it doesn't exist
        $email = $samlData['nameId'];
        $username = explode('@', $email)[0];

        // Ensure username is unique
        $baseUsername = $username;
        $counter = 1;
        while ($this->usernameExists($username)) {
          $username = $baseUsername . $counter;
          $counter++;
        }

        $user = \Civi\Api4\User::create(FALSE)
          ->addValue('username', $username)
          ->addValue('contact_id', $contactId)
          ->addValue('is_active', TRUE)
          ->execute()
          ->first();

        SamlService::debug('Created new user', [
          'user_id' => $user['id'],
          'username' => $username,
        ]);
      }

      // Assign roles from SAML attributes (if configured)
      SamlService::assignRoles($user['id'], $samlData);

      // Log the user in using CiviCRM Standalone authentication
      $session = \CRM_Core_Session::singleton();
      $session->set('userID', $contactId);
      $session->set('ufID', $user['id']);

      // Store SAML session data for potential logout
      $session->set('saml_sessionIndex', $samlData['sessionIndex'] ?? NULL);
      $session->set('saml_nameId', $samlData['nameId']);

      SamlService::debug('Session established');

      // Redirect to the CiviCRM home page
      \CRM_Utils_System::redirect(\CRM_Utils_System::url('civicrm/dashboard', 'reset=1'));
    }
    catch (\Exception $e) {
      SamlService::debug('SAML ACS error', ['error' => $e->getMessage()]);
      \CRM_Core_Error::statusBounce('SAML authentication failed: ' . $e->getMessage());
    }
  }

  /**
   * Check if a username already exists
   *
   * @param string $username
   * @return bool
   */
  private function usernameExists(string $username): bool {
    $existing = \Civi\Api4\User::get(FALSE)
      ->addWhere('username', '=', $username)
      ->selectRowCount()
      ->execute();

    return $existing->count() > 0;
  }

}
