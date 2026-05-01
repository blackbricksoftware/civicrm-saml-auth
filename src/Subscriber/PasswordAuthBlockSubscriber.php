<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSamlAuth\Subscriber;

use BlackBrickSoftware\CiviCRMSamlAuth\Service\ConfigProvider;
use Civi\API\Event\AuthorizeEvent;
use Civi\Standalone\Event\LoginEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Blocks every password-auth path when saml_auth_mode is `required`.
 *
 * Without this, the UI redirects in LoginFormSubscriber are bypassable: anyone
 * with a password (or a still-valid reset token) could POST directly to one of
 * the User.* APIs and authenticate. Two layers:
 *
 * 1. civi.standalone.login (pre_credentials_check, pre_send_password_reset)
 *    — short-circuits User.login and User.RequestPasswordResetEmail before any
 *    password is checked or reset-email is sent.
 *
 * 2. civi.api.authorize for User.PasswordReset — that action does NOT dispatch
 *    a LoginEvent. Token validation alone proves possession of a previously-
 *    issued reset URL, which would otherwise let a user trade it for a fresh
 *    password and bypass SSO. We refuse to authorize the request when SAML is
 *    required.
 */
class PasswordAuthBlockSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly ConfigProvider $config) {}

  public static function getSubscribedEvents(): array {
    return [
      'civi.standalone.login' => 'onLoginEvent',
      'civi.api.authorize' => 'onApiAuthorize',
    ];
  }

  public function onLoginEvent(LoginEvent $event): void {
    if ($this->config->mode() !== ConfigProvider::MODE_REQUIRED) {
      return;
    }
    if (!in_array($event->stage, ['pre_credentials_check', 'pre_send_password_reset'], TRUE)) {
      return;
    }
    if ($event->stopReason !== NULL) {
      return;
    }
    $event->stopReason = 'loginPrevented';
    $this->config->debug('Password auth blocked by SAML required-mode', [
      'stage' => $event->stage,
      'userID' => $event->userID,
    ]);
  }

  public function onApiAuthorize(AuthorizeEvent $event): void {
    if ($this->config->mode() !== ConfigProvider::MODE_REQUIRED) {
      return;
    }
    $req = $event->getApiRequest();
    // APIv4 action names are lcfirst (e.g. "passwordReset"); v3 used "PasswordReset".
    // Compare case-insensitively so the block holds regardless of caller convention.
    $entity = $req['entity'] ?? NULL;
    $action = $req['action'] ?? NULL;
    if ($entity !== 'User' || strcasecmp((string) $action, 'PasswordReset') !== 0) {
      return;
    }
    $this->config->debug('User.PasswordReset blocked by SAML required-mode');
    $event->setAuthorized(FALSE);
    $event->stopPropagation();
  }

}
