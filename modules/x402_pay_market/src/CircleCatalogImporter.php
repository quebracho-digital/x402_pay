<?php

declare(strict_types=1);

namespace Drupal\x402_pay_market;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Imports the Circle Agent Marketplace catalogue as listing products.
 *
 * The Discovery API is public and read-only. Each resource becomes one
 * listing keyed by method + URL; listings Circle no longer returns are
 * unpublished, never deleted, so their reviews survive.
 */
final class CircleCatalogImporter {

  public const API = 'https://api.circle.com/v2/x402/discovery/resources';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Imports every page; returns counts per outcome.
   *
   * @param int $max
   *   Stop after this many resources (0 = all). For trying it out.
   *
   * @throws \RuntimeException
   *   When another import (cron or drush) is already running: two at once
   *   both miss each other's new listings and create duplicates.
   */
  public function import(int $max = 0): array {
    if (!$this->lock->acquire('x402_pay_market.circle_import', 3600)) {
      throw new \RuntimeException('A Circle catalogue import is already running.');
    }
    try {
      return $this->doImport($max);
    }
    finally {
      $this->lock->release('x402_pay_market.circle_import');
    }
  }

  private function doImport(int $max): array {
    $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'unpublished' => 0];
    $seen = [];
    $offset = 0;
    do {
      $page = json_decode((string) $this->httpClient->request('GET', self::API, [
        'query' => ['limit' => 100, 'offset' => $offset],
        'timeout' => 30,
      ])->getBody(), TRUE);
      foreach ($page['items'] ?? [] as $item) {
        $outcome = $this->upsert($item);
        $counts[$outcome]++;
        if ($outcome !== 'skipped') {
          $seen[$this->key($item)] = TRUE;
        }
        if ($max && array_sum($counts) >= $max) {
          break 2;
        }
      }
      $offset += 100;
    } while ($offset < ($page['pagination']['total'] ?? 0));

    // Only a complete run knows what disappeared.
    if (!$max) {
      $counts['unpublished'] = $this->unpublishMissing($seen);
      \Drupal::state()->set('x402_pay_market.circle_last', \Drupal::time()->getRequestTime());
    }
    $this->loggerFactory->get('x402_pay_market')->info('Circle catalogue import: @c', ['@c' => json_encode($counts)]);
    return $counts;
  }

  private function key(array $item): string {
    return strtoupper($item['metadata']['method'] ?? 'GET') . ' ' . $item['resource'];
  }

  /**
   * Creates or updates the listing for one resource.
   */
  private function upsert(array $item): string {
    $accepts = array_values(array_filter($item['accepts'] ?? [], fn($a) => ($a['scheme'] ?? '') === 'exact' && isset($a['amount'], $a['payTo'])));
    if (!$accepts || empty($item['resource'])) {
      return 'skipped';
    }
    $meta = $item['metadata'] ?? [];
    $provider = $meta['provider'] ?? [];
    $method = strtoupper($meta['method'] ?? 'GET');

    $products = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $products->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_endpoint', $item['resource'])
      ->condition('field_http_method', $method)
      ->range(0, 1)->execute();
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $ids ? $products->load(reset($ids)) : NULL;
    $updated = $item['lastUpdated'] ?? '';
    if ($product && $product->get('field_source_updated')->value === $updated && $product->isPublished()) {
      return 'unchanged';
    }

    // Arc first (the hackathon chain), then the cheapest offer.
    usort($accepts, fn($a, $b) => [!str_starts_with($a['network'], 'eip155:5042'), (int) $a['amount']] <=> [!str_starts_with($b['network'], 'eip155:5042'), (int) $b['amount']]);
    $best = $accepts[0];
    // ponytail: assumes every asset is USDC (6 decimals), true for the whole
    // catalogue today; read the decimals per asset if non-USDC listings appear.
    $price = new Price(Calculator::divide((string) $best['amount'], '1000000', 6), 'USD');

    $title = trim($meta['description'] ?? '') ?: (($provider['name'] ?? 'Service') . ' ' . ($meta['path'] ?? ''));
    $values = [
      'title' => mb_substr($title, 0, 255),
      'body' => ['value' => $provider['description'] ?? '', 'format' => 'plain_text'],
      'field_endpoint' => $item['resource'],
      'field_http_method' => $method,
      'field_pay_to' => $best['payTo'],
      'field_network' => array_values(array_unique(array_column($accepts, 'network'))),
      'field_accepts' => json_encode($accepts, JSON_UNESCAPED_SLASHES),
      'field_input_schema' => isset($meta['input']) ? json_encode($meta['input'], JSON_UNESCAPED_SLASHES) : NULL,
      'field_output_schema' => isset($meta['output']) ? json_encode($meta['output'], JSON_UNESCAPED_SLASHES) : NULL,
      'field_provider' => mb_substr($provider['name'] ?? '', 0, 255),
      'field_docs_url' => $provider['docsUrl'] ?? $provider['website'] ?? NULL,
      'field_source' => 'circle',
      'field_verified' => FALSE,
      'field_source_updated' => $updated,
      'field_category' => $this->term('listing_category', $provider['category'] ?? 'OTHER'),
      'field_tags' => array_map(fn($t) => $this->term('tags', $t), array_slice($provider['tags'] ?? [], 0, 10)),
      'status' => TRUE,
    ];

    $outcome = $product ? 'updated' : 'created';
    if (!$product) {
      $store = $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
      $product = $products->create(['type' => 'listing', 'stores' => [$store]]);
    }
    foreach ($values as $field => $value) {
      $product->set($field, $value);
    }
    $variation = $product->getDefaultVariation() ?? $this->entityTypeManager->getStorage('commerce_product_variation')->create(['type' => 'listing']);
    $variation->setSku('circle-' . substr(hash('sha256', $this->key($item)), 0, 16));
    $variation->setPrice($price);
    $variation->save();
    if (!$product->hasVariation($variation)) {
      $product->addVariation($variation);
    }
    $product->save();
    return $outcome;
  }

  /**
   * Loads or creates a term by name.
   */
  private function term(string $vid, string $name): ?int {
    $name = mb_substr(trim($name), 0, 255);
    if ($name === '') {
      return NULL;
    }
    static $cache = [];
    if (isset($cache[$vid][$name])) {
      return $cache[$vid][$name];
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term');
    $found = $terms->loadByProperties(['vid' => $vid, 'name' => $name]);
    $term = $found ? reset($found) : $terms->create(['vid' => $vid, 'name' => $name]);
    if ($term->isNew()) {
      $term->save();
    }
    return $cache[$vid][$name] = (int) $term->id();
  }

  /**
   * Unpublishes Circle listings that the last full run did not return.
   */
  private function unpublishMissing(array $seen): int {
    $products = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $products->getQuery()->accessCheck(FALSE)
      ->condition('type', 'listing')
      ->condition('field_source', 'circle')
      ->condition('status', 1)
      ->execute();
    $n = 0;
    foreach ($products->loadMultiple($ids) as $product) {
      $key = $product->get('field_http_method')->value . ' ' . $product->get('field_endpoint')->value;
      if (!isset($seen[$key])) {
        $product->setUnpublished()->save();
        $n++;
      }
    }
    return $n;
  }

}
