<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Drupal\x402_pay\Catalog;
use Drupal\x402_pay\Delivery;
use Drupal\x402_pay\Event\AgentHomeEvent;
use Drupal\x402_pay\PathProcessor\AgentHomePathProcessor;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The home page for agents: /index.md, and /es.md in each language.
 *
 * The page chosen in the settings (the front page by default) in a view mode
 * of its own ("agent", else its default display), what the site sells, and
 * what other modules add (AgentHomeEvent). A view mode that is sold is never
 * shown here: its link is.
 */
final class AgentHomeController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly PathValidatorInterface $pathValidator,
    private readonly EventDispatcherInterface $eventDispatcher,
    #[Autowire(service: 'x402_pay.delivery')]
    private readonly Delivery $delivery,
    #[Autowire(service: 'x402_pay.catalog')]
    private readonly Catalog $catalog,
    #[Autowire(service: 'x402_pay.agent_home_path_processor')]
    private readonly AgentHomePathProcessor $paths,
  ) {}

  /**
   * The page, the catalogue and the other modules' sections, as markdown.
   */
  public function __invoke(Request $request): CacheableResponse {
    $settings = $this->configFactory->get('x402_pay.settings');
    $site = $this->configFactory->get('system.site');
    $cache = (new CacheableMetadata())
      ->addCacheableDependency($settings)
      ->addCacheableDependency($site)
      ->addCacheContexts(['url.path', 'user.permissions']);
    if ($this->languageManager->isMultilingual()) {
      $cache->addCacheableDependency($this->configFactory->get('language.negotiation'));
    }
    $langcode = AgentHomePathProcessor::langcode($request->getPathInfo(), $this->paths->prefixes()) ?: $this->languageManager->getCurrentLanguage()->getId();
    $home = (array) $settings->get('agent_home') + ['path' => '', 'view_mode' => 'agent', 'catalog' => TRUE];
    $entity = $this->entity($home['path'] ?: (string) $site->get('page.front'), $langcode, $cache);

    $event = new AgentHomeEvent($request, $langcode, $entity, $cache);
    $event->addSection($entity ? $this->page($entity, $home['view_mode'], $langcode, $cache) : '# ' . $site->get('name'));
    if ($home['catalog']) {
      $event->addSection($this->sold($request, $langcode, $cache), 10);
    }
    $this->eventDispatcher->dispatch($event);

    $markdown = implode("\n\n", array_filter($event->sections())) . "\n";
    $response = new CacheableResponse($markdown, 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
    return $response->addCacheableDependency($cache);
  }

  /**
   * The entity whose page a path is, in a language, if the visitor sees it.
   */
  private function entity(string $path, string $langcode, CacheableMetadata $cache): ?FieldableEntityInterface {
    $url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($path);
    if (!$url instanceof Url || !$url->isRouted() || !preg_match('/^entity\.(\w+)\.canonical$/', $url->getRouteName(), $m)) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($m[1])->load($url->getRouteParameters()[$m[1]] ?? NULL);
    if (!$entity instanceof FieldableEntityInterface) {
      return NULL;
    }
    if ($entity instanceof TranslatableInterface && $entity->hasTranslation($langcode)) {
      $entity = $entity->getTranslation($langcode);
    }
    $access = $entity->access('view', NULL, TRUE);
    $cache->addCacheableDependency($entity)->addCacheableDependency($access);
    return $access->isAllowed() ? $entity : NULL;
  }

  /**
   * The entity in the view mode, or the link to buy it if that is sold.
   */
  private function page(FieldableEntityInterface $entity, string $viewMode, string $langcode, CacheableMetadata $cache): string {
    foreach ($entity->getFieldDefinitions() as $definition) {
      if ($definition->getType() === 'x402_price') {
        // A field starting to sell this view mode changes the page.
        $cache->addCacheableDependency($definition);
      }
    }
    if (!X402PriceItem::find($entity, $viewMode)) {
      return $this->delivery->markdownText($entity, $viewMode);
    }
    $url = Url::fromRoute('x402_pay.entity', [
      'entity_type' => $entity->getEntityTypeId(),
      'entity' => $entity->id(),
      'view_mode' => $viewMode,
    ], ['absolute' => TRUE, 'language' => $this->languageManager->getLanguage($langcode)]);
    $url = $url->toString(TRUE);
    $cache->addCacheableDependency($url);
    return '# ' . $entity->label() . "\n\nSold with x402: " . $url->getGeneratedUrl();
  }

  /**
   * What the site sells, one link each, and where its terms are.
   */
  private function sold(Request $request, string $langcode, CacheableMetadata $cache): string {
    $items = $this->catalog->items($request, $cache->addCacheTags(['entity_field_info']));
    if (!$items) {
      return '';
    }
    $lines = ['## Sold here with x402', ''];
    foreach ($items as $item) {
      $lines[] = '- [' . $item['metadata']['description'] . '](' . $item['resource'] . ')';
    }
    $discovery = Url::fromRoute('x402_pay.discovery', [], [
      'absolute' => TRUE,
      'language' => $this->languageManager->getLanguage($langcode),
    ])->toString(TRUE);
    $cache->addCacheableDependency($discovery);
    $discovery = $discovery->getGeneratedUrl();
    $lines[] = '';
    $lines[] = "Prices and payment terms: $discovery";
    return implode("\n", $lines);
  }

}
