<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\FieldConfigInterface;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Drupal\x402_pay\Sweeper;

/**
 * Hooks for x402 Pay.
 */
class X402PayHooks {

  public function __construct(private readonly Sweeper $sweeper) {}

  /**
   * Sweeps the seller wallets to the cold wallet, when on and due.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->sweeper->cron();
  }

  /**
   * Refuses a field that would sell what another already sells.
   *
   * The settings form says so first; this covers the API and config sync.
   */
  #[Hook('field_config_presave')]
  public function fieldConfigPresave(FieldConfigInterface $field): void {
    if ($field->getType() === 'x402_price' && ($conflict = X402PriceItem::conflict($field))) {
      throw new \LogicException(strip_tags((string) $conflict));
    }
  }

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

  /**
   * The payment log in Views: sales lists, per entity sold or per seller.
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    $string = fn(\Stringable $title, \Stringable $help) => [
      'title' => $title,
      'help' => $help,
      'field' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
      'argument' => ['id' => 'string'],
      'sort' => ['id' => 'standard'],
    ];
    return ['x402_pay_log' => [
      'table' => [
        'group' => t('x402 payments'),
        'base' => ['field' => 'id', 'title' => t('x402 payments'), 'help' => t('Every paid request: settled, not settled or failed.')],
      ],
      'id' => ['title' => t('ID'), 'help' => t('The log entry.'), 'field' => ['id' => 'numeric'], 'sort' => ['id' => 'standard'], 'filter' => ['id' => 'numeric']],
      'created' => ['title' => t('Time'), 'help' => t('When it was paid.'), 'field' => ['id' => 'date'], 'sort' => ['id' => 'date'], 'filter' => ['id' => 'date']],
      'resource' => ['field' => ['id' => 'url']] + $string(t('Resource'), t('The URL bought.')),
      'network' => $string(t('Network'), t('CAIP-2, e.g. eip155:5042 (Arc).')),
      'amount' => ['title' => t('Amount'), 'help' => t('In the token, e.g. 0.01 USDC.'), 'field' => ['id' => 'x402_amount'], 'sort' => ['id' => 'standard']],
      'pay_to' => $string(t('Paid to'), t('The seller\'s address.')),
      'payer' => $string(t('Payer'), t('The buyer\'s address.')),
      'status' => $string(t('Status'), t('settled, not_settled or failed.')),
      'transaction' => ['field' => ['id' => 'x402_transaction']] + $string(t('Transaction'), t('Its hash, linked to the explorer.')),
      'handler_status' => ['title' => t('Delivery status'), 'help' => t('The HTTP status the seller answered.'), 'field' => ['id' => 'numeric'], 'filter' => ['id' => 'numeric']],
      'error' => $string(t('Error'), t('Why it was not settled.')),
      'entity_type' => $string(t('Entity type'), t('What was sold: its type…')),
      'entity_id' => $string(t('Entity ID'), t('…and its id.')),
    ]];
  }

}
