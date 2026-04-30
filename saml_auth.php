<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'saml_auth.civix.php';
// phpcs:enable

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

// Composer autoload for the onelogin/php-saml dependency.
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
  require_once $autoloadPath;
}

/**
 * Implements hook_civicrm_container().
 *
 * Each feature is a single EventSubscriber class under
 * BlackBrickSoftware\CiviCRMSamlAuth\Subscriber\. Toggle a feature by commenting its
 * addSubscriber line.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_container/
 */
function saml_auth_civicrm_container(ContainerBuilder $container): void {
  $container->autowire(\BlackBrickSoftware\CiviCRMSamlAuth\Service\ConfigProvider::class)->setPublic(TRUE);
  $container->autowire(\BlackBrickSoftware\CiviCRMSamlAuth\Service\UserMatcher::class)->setPublic(TRUE);
  $container->autowire(\BlackBrickSoftware\CiviCRMSamlAuth\Service\RelayStateValidator::class)->setPublic(TRUE);
  $container->autowire(\BlackBrickSoftware\CiviCRMSamlAuth\Service\SamlService::class)->setPublic(TRUE);

  $container->findDefinition('dispatcher')
    // Comment any line to disable the feature.
    ->addMethodCall('addSubscriber', [new Definition(\BlackBrickSoftware\CiviCRMSamlAuth\Subscriber\LoginFormSubscriber::class, [new Reference(\BlackBrickSoftware\CiviCRMSamlAuth\Service\ConfigProvider::class)])])
    ->addMethodCall('addSubscriber', [new Definition(\BlackBrickSoftware\CiviCRMSamlAuth\Subscriber\SettingsFormSubscriber::class, [new Reference(\BlackBrickSoftware\CiviCRMSamlAuth\Service\ConfigProvider::class)])])
    ->addMethodCall('addSubscriber', [new Definition(\BlackBrickSoftware\CiviCRMSamlAuth\Subscriber\NavigationMenuSubscriber::class)])
  ;
}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function saml_auth_civicrm_config(&$config): void {
  _saml_auth_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function saml_auth_civicrm_install(): void {
  _saml_auth_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function saml_auth_civicrm_enable(): void {
  _saml_auth_civix_civicrm_enable();
}
