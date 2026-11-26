<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\x402_pay\Event\PriceEvent;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Everything the site sells, as Circle Discovery items.
 *
 * Shared by /x402/discovery and any other list of products.
 */
final class Catalog {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly Delivery $delivery,
  ) {}

  /**
   * Everything the site sells to this visitor, one item per product.
   *
   * In the shape of a Circle Discovery item.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The visitor's request, which each product is quoted for.
   * @param \Drupal\Core\Cache\CacheableMetadata $cache
   *   Collects what the list depends on.
   * @param string|null $langcode
   *   The language of labels and links; NULL is the negotiated one.
   *
   * @return array[]
   *   One Discovery item per product.
   */
  public function items(Request $request, CacheableMetadata $cache, ?string $langcode = NULL): array {
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
        $entity = $this->entityRepository->getTranslationFromContext($entity, $langcode);
        $access = $entity->access('view', NULL, TRUE);
        $cache->addCacheableDependency($entity)->addCacheableDependency($access);
        if ($access->isAllowed()) {
          foreach (X402PriceItem::all($entity) as $item) {
            $quote = $this->delivery->quote($request, $entity, $item);
            try {
              if (!$quote->isDenied()) {
                $items[] = $this->item($entity, $item, $quote, $cache);
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
    return $items;
  }

  /**
   * One product in the shape of a Circle Discovery item.
   */
  private function item($entity, X402PriceItem $item, PriceEvent $quote, CacheableMetadata $cache): array {
    $url = $item->isCanonical()
      ? $entity->toUrl('canonical', ['absolute' => TRUE])
      : Url::fromRoute('x402_pay.entity', [
        'entity_type' => $entity->getEntityTypeId(),
        'entity' => $entity->id(),
        'view_mode' => $item->viewMode(),
      ], ['absolute' => TRUE, 'language' => $entity->language()]);
    // An alias or a language prefix in the link: its own cache metadata.
    $url = $url->toString(TRUE);
    $cache->addCacheableDependency($url);
    return [
      'resource' => $url->getGeneratedUrl(),
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
