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
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\HttpFoundation\Request;

/**
 * /x402/discovery: everything this site sells, shaped like Circle's Discovery.
 *
 * One item per product (an entity's view mode at a price), across every
 * entity type with an x402_price field, so a client written for Circle's
 * Agent Marketplace reads it unchanged. List prices: the price event may
 * charge otherwise at the time of sale. Query: limit (max 100), offset.
 */
final class DiscoveryController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityRepositoryInterface $entityRepository,
  ) {}

  public function __invoke(Request $request): CacheableJsonResponse {
    $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
    $offset = max(0, (int) $request->query->get('offset', 0));
    $cache = (new CacheableMetadata())
      ->addCacheTags(['entity_field_info'])
      ->addCacheContexts(['url.query_args:limit', 'url.query_args:offset', 'user.permissions', 'languages:language_content']);

    // ponytail: loads every entity with a price to count products; an index
    // (Search API) when a site sells more than a few thousand.
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
            $items[] = $this->item($entity, $item);
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

  private function item($entity, X402PriceItem $item): array {
    $url = $item->isCanonical()
      ? $entity->toUrl('canonical', ['absolute' => TRUE])
      : Url::fromRoute('x402_pay.entity', ['entity_type' => $entity->getEntityTypeId(), 'entity' => $entity->id(), 'view_mode' => $item->viewMode()], ['absolute' => TRUE, 'language' => $entity->language()]);
    return [
      'resource' => $url->toString(TRUE)->getGeneratedUrl(),
      'type' => 'http',
      'x402Version' => 2,
      'lastUpdated' => $entity instanceof EntityChangedInterface ? date('c', $entity->getChangedTime()) : NULL,
      'accepts' => [$item->requirements()],
      'metadata' => [
        'description' => (string) $entity->label(),
        'method' => 'GET',
        'mimeType' => 'text/markdown',
        'viewMode' => $item->viewMode(),
        'language' => $entity->language()->getId(),
      ],
    ];
  }

}
