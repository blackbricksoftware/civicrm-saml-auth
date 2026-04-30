<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSamlAuth\Subscriber;

use Civi\Core\Event\GenericHookEvent;
use BlackBrickSoftware\CiviCRMSamlAuth\Service\ConfigProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Modifies the Standalone login page to honour saml_auth_mode:
 *
 *  - disabled: does nothing.
 *  - optional: injects the "Login with SSO" button template.
 *  - required: 302s to /civicrm/saml/login so the password form never renders.
 */
class LoginFormSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly ConfigProvider $config) {}

  public static function getSubscribedEvents(): array {
    return ['hook_civicrm_buildForm' => 'onBuildForm'];
  }

  public function onBuildForm(GenericHookEvent $event): void {
    if ($event->formName !== 'CRM_Standaloneusers_Form_Login') {
      return;
    }

    $mode = $this->config->mode();
    if ($mode === ConfigProvider::MODE_DISABLED) {
      return;
    }

    $samlLoginUrl = \CRM_Utils_System::url('civicrm/saml/login', '', TRUE, NULL, FALSE);

    if ($mode === ConfigProvider::MODE_REQUIRED) {
      $this->config->debug('Login mode=required — redirecting to IdP');
      \CRM_Utils_System::redirect($samlLoginUrl);
      // redirect() exits; fall through is just defensive.
      return;
    }

    // MODE_OPTIONAL.
    $event->form->assign('samlLoginUrl', $samlLoginUrl);
    \CRM_Core_Region::instance('page-body')->add([
      'template' => 'CRM/SamlAuth/SsoLoginButton.tpl',
    ]);
  }

}
