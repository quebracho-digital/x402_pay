<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Drupal\x402_pay\Event\AgentHomeEvent;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The home page for agents: at /index.md and /es.md, and at its page's .md.
 *
 * The page chosen in the settings (the front page by default) in a view mode
 * of its own ("agent", else its default display), what the site sells, and
 * what other modules add (AgentHomeEvent). A view mode that is sold is never
 * shown here: its link is.
 */
final class AgentHome {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly PathValidatorInterface $pathValidator,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly Delivery $delivery,
    private readonly Catalog $catalog,
  ) {}

  /**
   * The page, the catalogue and the other modules' sections, as markdown.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The agent's request.
   * @param string|null $langcode
   *   The language the path names (/es.md); NULL is the negotiated one.
   */
  public function response(Request $request, ?string $langcode = NULL): CacheableResponse {
    $settings = $this->configFactory->get('x402_pay.settings');
    $site = $this->configFactory->get('system.site');
    $cache = (new CacheableMetadata())
      ->addCacheableDependency($settings)
      ->addCacheableDependency($site)
      ->addCacheContexts(['url.path', 'user.permissions']);
    if ($this->languageManager->isMultilingual()) {
      // Without a language in the path, the negotiated one.
      $cache->addCacheableDependency($this->configFactory->get('language.negotiation'))
        ->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);
    }
    $langcode = $langcode ?: $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $home = $this->settings();
    $entity = $this->entity($home['path'], $langcode, $cache);

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
   * Whether an entity is the page the home page shows.
   *
   * Its own ".md" is then the home page too: one home page for agents,
   * however they reach it.
   */
  public function isHome(FieldableEntityInterface $entity): bool {
    $url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($this->settings()['path']);
    $type = $entity->getEntityTypeId();
    return $url instanceof Url && $url->isRouted() && $url->getRouteName() === "entity.$type.canonical"
      && (string) ($url->getRouteParameters()[$type] ?? '') === (string) $entity->id();
  }

  /**
   * The settings, with the front page when no page is chosen.
   *
   * @return array{path: string, view_mode: string, catalog: bool}
   *   The page's path, its view mode, and whether to list what is sold.
   */
  private function settings(): array {
    $home = (array) $this->configFactory->get('x402_pay.settings')->get('agent_home') + [
      'path' => '',
      'view_mode' => 'agent',
      'catalog' => TRUE,
    ];
    $home['path'] = $home['path'] ?: (string) $this->configFactory->get('system.site')->get('page.front');
    return $home;
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
   * The entity in the view mode, or the link to buy it if the page is sold.
   *
   * Sold as the gate sells the page's markdown: on its own URL, in full at
   * its x402 URL, or drawn with the display shown here (Delivery::soldIn()).
   * Any of them: the same fields.
   */
  private function page(FieldableEntityInterface $entity, string $viewMode, string $langcode, CacheableMetadata $cache): string {
    foreach ($entity->getFieldDefinitions() as $definition) {
      if ($definition->getType() === 'x402_price') {
        // A field starting to sell this view mode changes the page.
        $cache->addCacheableDependency($definition);
      }
    }
    $cache->addCacheableDependency(EntityViewDisplay::collectRenderDisplay($entity, $viewMode));
    $item = X402PriceItem::find($entity)
      ?? X402PriceItem::find($entity, 'full')
      ?? Delivery::soldIn($entity, $viewMode);
    if (!$item) {
      return $this->delivery->markdownText($entity, $viewMode, $cache);
    }
    $options = ['absolute' => TRUE, 'language' => $this->languageManager->getLanguage($langcode)];
    $url = $item->isCanonical() ? $entity->toUrl('canonical', $options) : Url::fromRoute('x402_pay.entity', [
      'entity_type' => $entity->getEntityTypeId(),
      'entity' => $entity->id(),
      'view_mode' => $item->viewMode(),
    ], $options);
    $url = $url->toString(TRUE);
    $cache->addCacheableDependency($url);
    return '# ' . $entity->label() . "\n\nSold with x402: " . $url->getGeneratedUrl();
  }

  /**
   * What the site sells, one link each, and where its terms are.
   */
  private function sold(Request $request, string $langcode, CacheableMetadata $cache): string {
    // In the page's language: /es.md is not a prefix to core's negotiation.
    $items = $this->catalog->items($request, $cache->addCacheTags(['entity_field_info']), $langcode);
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
