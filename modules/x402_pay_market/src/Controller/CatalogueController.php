<?php

declare(strict_types=1);

namespace Drupal\x402_pay_market\Controller;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The catalogue for agents, shaped like Circle's Discovery API (v2).
 *
 * Same item shape (resource, accepts, metadata), so a client written for
 * Circle's Agent Marketplace reads this market unchanged. Query: search,
 * network, category (term name), limit (max 100), offset.
 */
final class CatalogueController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  public function __invoke(Request $request): JsonResponse {
    $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
    $offset = max(0, (int) $request->query->get('offset', 0));

    $query = $this->entityTypeManager->getStorage('search_api_index')->load('listings')->query()
      ->range($offset, $limit);
    if ($keys = trim((string) $request->query->get('search', ''))) {
      $query->keys($keys);
    }
    if ($network = $request->query->get('network')) {
      $query->addCondition('network', $network);
    }
    if ($category = $request->query->get('category')) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'listing_category', 'name' => $category]);
      $query->addCondition('category', $terms ? (int) reset($terms)->id() : -1);
    }
    $results = $query->execute();

    $items = [];
    foreach ($results->getResultItems() as $item) {
      $product = $item->getOriginalObject()?->getValue();
      if ($product instanceof ProductInterface) {
        $items[] = $this->item($product);
      }
    }
    return new JsonResponse([
      'x402Version' => 2,
      'items' => $items,
      'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $results->getResultCount()],
    ]);
  }

  private function item(ProductInterface $product): array {
    $category = $product->get('field_category')->entity?->label();
    $rating = x402_pay_market_rating($product);
    return [
      'resource' => $product->get('field_endpoint')->value,
      'type' => 'http',
      'x402Version' => 2,
      'lastUpdated' => $product->get('field_source_updated')->value ?: date('c', $product->getChangedTime()),
      'accepts' => json_decode($product->get('field_accepts')->value ?? '[]', TRUE) ?: [],
      'metadata' => [
        'provider' => [
          'name' => $product->get('field_provider')->value,
          'docsUrl' => $product->get('field_docs_url')->value,
          'description' => $product->get('body')->value,
          'category' => $category,
          'tags' => array_map(fn($t) => $t->label(), $product->get('field_tags')->referencedEntities()),
        ],
        'method' => $product->get('field_http_method')->value,
        'description' => $product->getTitle(),
        'input' => json_decode($product->get('field_input_schema')->value ?? 'null', TRUE),
        'output' => json_decode($product->get('field_output_schema')->value ?? 'null', TRUE),
        // Ours beyond Circle's shape: who vouches for it, and what buyers say.
        'market' => [
          'url' => $product->toUrl('canonical', ['absolute' => TRUE])->toString(),
          'source' => $product->get('field_source')->value,
          'verified' => (bool) $product->get('field_verified')->value,
          'rating' => $rating,
        ],
      ],
    ];
  }

}
