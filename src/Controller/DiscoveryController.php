<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\x402_pay\Catalog;
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
    #[Autowire(service: 'x402_pay.catalog')]
    private readonly Catalog $catalog,
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

    $items = $this->catalog->items($request, $cache);

    $response = new CacheableJsonResponse([
      'x402Version' => 2,
      'items' => array_slice($items, $offset, $limit),
      'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => count($items)],
    ]);
    return $response->addCacheableDependency($cache);
  }

}
