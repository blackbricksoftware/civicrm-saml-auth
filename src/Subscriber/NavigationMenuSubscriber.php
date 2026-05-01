<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSamlAuth\Subscriber;

use Civi\Core\Event\GenericHookEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the "SAML Authentication" entry under Administer → Users and
 * Permissions. Comment out the addSubscriber line in saml_auth.php's
 * hook_civicrm_container to hide the admin menu entry.
 */
class NavigationMenuSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return ['hook_civicrm_navigationMenu' => 'onNavigationMenu'];
  }

  public function onNavigationMenu(GenericHookEvent $event): void {
    // The hook arg is named "params" (see CRM_Utils_Hook::navigationMenu()),
    // NOT "menu" — earlier versions of this code accessed $event->menu and
    // got a null reference, so the civix helper was no-op'ing and the SAML
    // entry never appeared in the admin menu.
    $menu = &$event->params;
    _saml_auth_civix_insert_navigation_menu($menu, 'Administer/Users and Permissions', [
      'label' => ts('SAML Authentication', ['domain' => 'saml_auth']),
      'name' => 'saml_authentication_settings',
      'url' => 'civicrm/admin/saml',
      'permission' => 'administer CiviCRM',
      'operator' => 'OR',
      'separator' => 0,
    ]);
    _saml_auth_civix_navigationMenu($menu);
  }

}
