<?php
declare(strict_types = 1);

namespace BlackBrickSoftware\CiviCRMSamlAuth\Service;

/**
 * Looks up a CiviCRM User by exactly one field — username or email —
 * based on ConfigProvider::matchField(). No priority-order fallback;
 * either it matches or it doesn't.
 */
class UserMatcher {

  public function __construct(private readonly ConfigProvider $config) {}

  /**
   * @return array|null The User record (with `id`, `contact_id`) or NULL.
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
      ->addWhere('is_active', '=', TRUE)
      ->addSelect('id', 'contact_id', 'username', 'uf_name')
      ->setLimit(1)
      ->execute()
      ->first();

    return $user ?: NULL;
  }

}
