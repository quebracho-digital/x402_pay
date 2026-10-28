<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\FieldConfigInterface;
use Drupal\x402_pay\ClaimStore;
use Drupal\x402_pay\Delivery;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Drupal\x402_pay\Sweeper;

/**
 * Hooks for x402 Pay.
 */
class X402PayHooks {

  public function __construct(
    private readonly Sweeper $sweeper,
    private readonly ClaimStore $claims,
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'x402_pay_markdown_link' => [
        'variables' => ['url' => NULL, 'attributes' => []],
      ],
    ];
  }

  /**
   * A free entity's page names its markdown in <head> and in a Link header.
   *
   * What an agent reading the HTML sees, and one reading only the headers.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $cacheability = new CacheableMetadata();
    if ($url = Delivery::markdownUrl($this->routeMatch, $cacheability)) {
      $attachments['#attached']['html_head_link'][] = [
        ['rel' => 'alternate', 'type' => 'text/markdown', 'href' => $url],
        TRUE,
      ];
    }
    CacheableMetadata::createFromRenderArray($attachments)->merge($cacheability)->applyTo($attachments);
  }

  /**
   * Sweeps the seller wallets when due, and forgets old payment claims.
   *
   * The wallets go to the cold wallet, if sweeping is on.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->sweeper->cron();
    $this->claims->prune();
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
   * What JSON:API reads beside its route's entity, when sold, it omits.
   *
   * A collection, an include (also in a POST's or PATCH's answer), a
   * relationship: never free. The route's own entity is the gate's
   * (AgentRequestSubscriber), which answers a GET before JSON:API does:
   * charged, or denied without view access. Those who read it free anyway
   * read it here too.
   */
  #[Hook('entity_access')]
  public function entityAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    $own = $this->routeMatch->getParameter('entity');
    // ponytail: JSON:API only, not Views' REST export (README). No route
    // context on neutral: JSON:API and pages never share a cache entry.
    if ($operation !== 'view' || !$this->routeMatch->getRouteObject()?->getDefault('_is_jsonapi')
      || $account->hasPermission('access x402 content free')
      || ($own instanceof EntityInterface && $own->getEntityTypeId() === $entity->getEntityTypeId() && $own->id() === $entity->id())
      || !X402PriceItem::all($entity)) {
      return AccessResult::neutral()->cachePerPermissions();
    }
    return AccessResult::forbidden('Sold: buy it at its x402 URL, listed at /x402/discovery.')
      ->addCacheableDependency($entity)
      ->addCacheContexts(['route'])
      ->cachePerPermissions();
  }

  /**
   * JSON:API filters and sorts by no field of a bundle that sells.
   *
   * Filtering by what an omitted entity holds would still tell it, match by
   * match.
   */
  #[Hook('jsonapi_entity_field_filter_access')]
  public function jsonapiEntityFieldFilterAccess(FieldDefinitionInterface $field_definition, AccountInterface $account): AccessResultInterface {
    // ponytail: base fields (title, author, dates) stay filterable, as
    // lists show them; an entity type selling a base field's text needs it
    // here too.
    $bundle = $field_definition->getTargetBundle();
    if ($field_definition->getFieldStorageDefinition()->isBaseField() || !$bundle || $account->hasPermission('access x402 content free')) {
      return AccessResult::neutral()->cachePerPermissions();
    }
    foreach ($this->entityFieldManager->getFieldDefinitions($field_definition->getTargetEntityTypeId(), $bundle) as $definition) {
      if ($definition->getType() === 'x402_price') {
        return AccessResult::forbidden('A bundle that sells is not filtered by its fields.')
          ->addCacheTags(['entity_field_info'])
          ->cachePerPermissions();
      }
    }
    return AccessResult::neutral()->addCacheTags(['entity_field_info'])->cachePerPermissions();
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
    return [
      'x402_pay_log' => [
        'table' => [
          'group' => t('x402 payments'),
          'base' => [
            'field' => 'id',
            'title' => t('x402 payments'),
            'help' => t('Every paid request: settled, not settled or failed.'),
          ],
        ],
        'id' => [
          'title' => t('ID'),
          'help' => t('The log entry.'),
          'field' => ['id' => 'numeric'],
          'sort' => ['id' => 'standard'],
          'filter' => ['id' => 'numeric'],
        ],
        'created' => [
          'title' => t('Time'),
          'help' => t('When it was paid.'),
          'field' => ['id' => 'date'],
          'sort' => ['id' => 'date'],
          'filter' => ['id' => 'date'],
        ],
        'resource' => ['field' => ['id' => 'url']] + $string(t('Resource'), t('The URL bought.')),
        'network' => $string(t('Network'), t('CAIP-2, e.g. eip155:5042 (Arc).')),
        'amount' => [
          'title' => t('Amount'),
          'help' => t('In the token, e.g. 0.01 USDC.'),
          'field' => ['id' => 'x402_amount'],
          'sort' => ['id' => 'standard'],
        ],
        'pay_to' => $string(t('Paid to'), t("The seller's address.")),
        'payer' => $string(t('Payer'), t("The buyer's address.")),
        'status' => $string(t('Status'), t('settled, not_settled or failed.')),
        'transaction' => ['field' => ['id' => 'x402_transaction']] + $string(t('Transaction'), t('Its hash, linked to the explorer.')),
        'handler_status' => [
          'title' => t('Delivery status'),
          'help' => t('The HTTP status the seller answered.'),
          'field' => ['id' => 'numeric'],
          'filter' => ['id' => 'numeric'],
        ],
        'error' => $string(t('Error'), t('Why it was not settled.')),
        'entity_type' => $string(t('Entity type'), t('What was sold: its type…')),
        'entity_id' => $string(t('Entity ID'), t('…and its id.')),
      ],
    ];
  }

}
