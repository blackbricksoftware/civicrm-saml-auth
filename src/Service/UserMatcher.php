<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSamlAuth\Service;

/**
 * Looks up a CiviCRM User by exactly one field — username or email —
 * based on ConfigProvider::matchField(). No priority-order fallback;
 * either it matches or it doesn't.
 *
 * Inactive users (is_active=0) are returned too — the caller decides
 * what to do with them (currently SamlService::findOrProvisionUser
 * throws so login is refused with a clear "account disabled" message).
 */
class UserMatcher {

  public function __construct(private readonly ConfigProvider $config) {}

  /**
   * @return array|null User record (`id`, `contact_id`, `username`, `uf_name`, `is_active`) or NULL.
   */
  public function find(string $identifier): ?array {
    $identifier = trim($identifier);
    if ($identifier === '') {
      return NULL;
    }

    $field = $this->config->matchField() === ConfigProvider::MATCH_USERNAME
      ? 'username'
      : 'uf_name';

    $user = \Civi\Api4\User::get(FALSE)
      ->addWhere($field, '=', $identifier)
      ->addSelect('id', 'contact_id', 'username', 'uf_name', 'is_active')
      ->setLimit(1)
      ->execute()
      ->first();

    return $user ?: NULL;
  }

}
