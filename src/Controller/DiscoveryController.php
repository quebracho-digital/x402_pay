<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\x402_pay\Delivery;
use Drupal\x402_pay\Event\PriceEvent;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Everything this site sells, shaped like Circle's Discovery.
 *
 * At /x402/discovery. One item per product (an entity's view mode at a
 * price), across every entity type with an x402_price field, so a client
 * written for Circle's Agent Marketplace reads it unchanged. Each product
 * is quoted as the sale will be (the price event: price, seller, what is
 * delivered), and what is denied is left out. Query: limit (max 100), offset.
 */
final class DiscoveryController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityRepositoryInterface $entityRepository,
    #[Autowire(service: 'x402_pay.delivery')]
    private readonly Delivery $delivery,
  ) {}

  /**
   * Everything the site sells, one item per product, paginated.
   */
  public function __invoke(Request $request): CacheableJsonResponse {
    $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
    $offset = max(0, (int) $request->query->get('offset', 0));
    $cache = (new CacheableMetadata())
      ->addCacheTags(['entity_field_info'])
      ->addCacheContexts([
        'url.query_args:limit',
        'url.query_args:offset',
        'user.permissions',
        'languages:language_content',
      ]);

    // ponytail: loads every entity with a price to count products; an index
    // (Search API) when a site sells more than a few thousand. Cached by
    // permissions: a price event varying by anything else must not let this
    // response be cached (no way to declare it yet).
    $items = [];
    foreach ($this->entityFieldManager->getFieldMapByFieldType('x402_price') as $entityType => $fields) {
      $cache->addCacheTags([$entityType . '_list']);
      $query = $this->entityTypeManager->getStorage($entityType)->getQuery()->accessCheck(TRUE);
      $charging = $query->orConditionGroup();
      foreach (array_keys($fields) as $name) {
        $charging->condition("$name.enabled", 1);
      }
      $ids = $query->condition($charging)->sort($this->entityTypeManager->getDefinition($entityType)->getKey('id'))->execute();
      foreach ($this->entityTypeManager->getStorage($entityType)->loadMultiple($ids) as $entity) {
        $entity = $this->entityRepository->getTranslationFromContext($entity);
        $access = $entity->access('view', NULL, TRUE);
        $cache->addCacheableDependency($entity)->addCacheableDependency($access);
        if ($access->isAllowed()) {
          foreach (X402PriceItem::all($entity) as $item) {
            $quote = $this->delivery->quote($request, $entity, $item);
            try {
              if (!$quote->isDenied()) {
                $items[] = $this->item($entity, $item, $quote);
              }
            }
            catch (\RuntimeException | \InvalidArgumentException) {
              // Cannot be sold (no usable seller key, a price the network
              // cannot charge): the sale logs why, the catalogue skips it.
            }
          }
        }
      }
    }

    $response = new CacheableJsonResponse([
      'x402Version' => 2,
      'items' => array_slice($items, $offset, $limit),
      'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => count($items)],
    ]);
    return $response->addCacheableDependency($cache);
  }

  /**
   * One product in the shape of a Circle Discovery item.
   */
  private function item($entity, X402PriceItem $item, PriceEvent $quote): array {
    $url = $item->isCanonical()
      ? $entity->toUrl('canonical', ['absolute' => TRUE])
      : Url::fromRoute('x402_pay.entity', [
        'entity_type' => $entity->getEntityTypeId(),
        'entity' => $entity->id(),
        'view_mode' => $item->viewMode(),
      ], ['absolute' => TRUE, 'language' => $entity->language()]);
    return [
      'resource' => $url->toString(TRUE)->getGeneratedUrl(),
      'type' => 'http',
      'x402Version' => 2,
      'lastUpdated' => $entity instanceof EntityChangedInterface ? date('c', $entity->getChangedTime()) : NULL,
      'accepts' => [$this->delivery->requirements($quote, $item)],
      'metadata' => [
        'description' => (string) $entity->label(),
        'method' => 'GET',
        'mimeType' => $quote->mimeType(),
        'viewMode' => $item->viewMode(),
        'language' => $entity->language()->getId(),
      ],
    ];
  }

}
