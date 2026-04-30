<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSamlAuth\Subscriber;

use Civi\Core\Event\GenericHookEvent;
use BlackBrickSoftware\CiviCRMSamlAuth\Service\ConfigProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * For each saml_auth_* field on the admin settings form: if the setting
 * is env-overridden, freeze the field and (for secrets) mask its value
 * so admins can see the config is externally managed without leaking
 * the actual cert/key text.
 */
class SettingsFormSubscriber implements EventSubscriberInterface {

  private const MASK = '•••••• (set via environment) ••••••';

  public function __construct(private readonly ConfigProvider $config) {}

  public static function getSubscribedEvents(): array {
    return ['hook_civicrm_buildForm' => 'onBuildForm'];
  }

  public function onBuildForm(GenericHookEvent $event): void {
    if ($event->formName !== 'CRM_SamlAuth_Form_Settings') {
      return;
    }

    $form = $event->form;
    $overridden = [];

    foreach ($form->_elements ?? [] as $element) {
      $name = $element->getName();
      if (!str_starts_with((string) $name, 'saml_auth_')) {
        continue;
      }
      if (!$this->config->isOverridden($name)) {
        continue;
      }

      $overridden[] = $name;

      if ($this->config->isSecret($name)) {
        $element->setValue(self::MASK);
      }
      $element->freeze();
    }

    $form->assign('samlAuthOverridden', $overridden);
  }

}
