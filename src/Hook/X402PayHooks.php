<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\FieldConfigInterface;
use Drupal\x402_pay\ClaimStore;
use Drupal\x402_pay\Delivery;
use Drupal\x402_pay\Ofac;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Drupal\x402_pay\Reconciler;
use Drupal\x402_pay\Sweeper;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hooks for x402 Pay.
 */
class X402PayHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly Sweeper $sweeper,
    private readonly ClaimStore $claims,
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Reconciler $reconciler,
    private readonly Ofac $ofac,
    #[Autowire(service: 'logger.channel.x402_pay')]
    private readonly LoggerInterface $logger,
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
   * Sweeps, forgets old payment claims, and checks the log against the chain.
   *
   * The wallets go to the cold wallet, if sweeping is on.
   */
  #[Hook('cron')]
  public function cron(): void {
    // Each step on its own: one that fails (a node answering garbage) must
    // not skip the others, least of all the sanctions list.
    foreach ([
      'OFAC list' => fn() => $this->ofac->refresh(),
      'sweeper' => fn() => $this->sweeper->cron(),
      'claims' => fn() => $this->claims->prune(),
      'reconciler' => fn() => $this->reconciler->cron(),
    ] as $step => $run) {
      try {
        $run();
      }
      catch (\Throwable $e) {
        $this->logger->error('Cron step "@step" failed: @message', [
          '@step' => $step,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Shows how old the sanctions list in use is; warns after a week.
   *
   * A site whose cron is broken keeps screening against a list that only
   * gets older, and nothing else would say so.
   *
   * @return array<string, array<string, mixed>>
   *   The status report line.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(string $phase): array {
    if ($phase !== 'runtime') {
      return [];
    }
    $age = $this->ofac->age();
    $stale = $age > 7 * 86400;
    $ok = enum_exists(RequirementSeverity::class) ? RequirementSeverity::OK : 0;
    $warning = enum_exists(RequirementSeverity::class) ? RequirementSeverity::Warning : 1;
    return [
      'x402_pay_ofac' => [
        'title' => $this->t('x402 Pay sanctions list'),
        'value' => $this->t('OFAC SDN list published @date', ['@date' => $this->ofac->list()->published()]),
        'description' => $stale ? $this->t('The list is over a week old: check that cron runs and can write to the files directory, or the payers are screened against an out-of-date list.') : '',
        'severity' => $stale ? $warning : $ok,
      ],
    ];
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
          'group' => $this->t('x402 payments'),
          'base' => [
            'field' => 'id',
            'title' => $this->t('x402 payments'),
            'help' => $this->t('Every paid request: settled, not settled or failed.'),
          ],
        ],
        'id' => [
          'title' => $this->t('ID'),
          'help' => $this->t('The log entry.'),
          'field' => ['id' => 'numeric'],
          'sort' => ['id' => 'standard'],
          'filter' => ['id' => 'numeric'],
        ],
        'created' => [
          'title' => $this->t('Time'),
          'help' => $this->t('When it was paid.'),
          'field' => ['id' => 'date'],
          'sort' => ['id' => 'date'],
          'filter' => ['id' => 'date'],
        ],
        'resource' => ['field' => ['id' => 'url']] + $string($this->t('Resource'), $this->t('The URL bought.')),
        'network' => $string($this->t('Network'), $this->t('CAIP-2, e.g. eip155:5042 (Arc).')),
        'amount' => [
          'title' => $this->t('Amount'),
          'help' => $this->t('In the token, e.g. 0.01 USDC.'),
          'field' => ['id' => 'x402_amount'],
          'sort' => ['id' => 'standard'],
        ],
        'pay_to' => $string($this->t('Paid to'), $this->t("The seller's address.")),
        'payer' => $string($this->t('Payer'), $this->t("The buyer's address.")),
        'status' => $string($this->t('Status'), $this->t('settled, not_settled or failed.')),
        'transaction' => ['field' => ['id' => 'x402_transaction']] + $string($this->t('Transaction'), $this->t('Its hash, linked to the explorer.')),
        'handler_status' => [
          'title' => $this->t('Delivery status'),
          'help' => $this->t('The HTTP status the seller answered.'),
          'field' => ['id' => 'numeric'],
          'filter' => ['id' => 'numeric'],
        ],
        'error' => $string($this->t('Error'), $this->t('Why it was not settled.')),
        'entity_type' => $string($this->t('Entity type'), $this->t('What was sold: its type…')),
        'entity_id' => $string($this->t('Entity ID'), $this->t('…and its id.')),
      ],
    ];
  }

}
