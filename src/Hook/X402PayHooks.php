<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Hooks for x402 Pay.
 */
class X402PayHooks {

  /**
   * Only those allowed can switch charging off or change an entity's price.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account): AccessResultInterface {
    if ($operation !== 'edit' || $field_definition->getType() !== 'x402_price') {
      return AccessResult::neutral();
    }
    return AccessResult::forbiddenIf(!$account->hasPermission('set x402 prices'))->cachePerPermissions();
  }

}
