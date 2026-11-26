<?php

declare(strict_types=1);

namespace Drupal\x402_pay\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Url;
use Drupal\x402_pay\AgentHome;
use Drupal\x402_pay\Delivery;
use Drupal\x402_pay\PathProcessor\MarkdownPathProcessor;
use Quebracho\X402\PaymentHeaders;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The one gate for what agents read: charged when sold, markdown when free.
 *
 * It decides by entity, not by route or format. On an entity's own URL it
 * charges what a field sells there; asked for markdown there (Accept, a .md
 * path) also the page's markdown when a field sells it at its x402 URL, and
 * else converts the page for free. On any other route that carries the
 * entity (JSON:API, REST) it charges anything sold, so no other module can
 * hand out for free what is sold. Browsers still get the page.
 * It runs after routing's access check: what a visitor cannot see is never
 * offered.
 */
final class AgentRequestSubscriber implements EventSubscriberInterface {

  /**
   * The view mode an entity's own URL shows.
   */
  private const PAGE = 'full';

  public function __construct(
    private readonly Delivery $delivery,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KillSwitch $pageCacheKillSwitch,
    private readonly AgentHome $agentHome,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After routing and its access check (32), before the dynamic page
    // cache could answer with the HTML page (27).
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
      KernelEvents::RESPONSE => ['onResponse'],
    ];
  }

  /**
   * Sells to an agent what the route shows; or the free page as markdown.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    $entity = $event->isMainRequest() && $request->isMethodCacheable() ? $this->entity($request) : NULL;
    if ($entity) {
      $this->answerAgent($event, $entity);
    }
    // A .md path is markdown or a sale, never the HTML page under its name;
    // a route that answers markdown itself (Markdownify's, another module's
    // in the markdown format) answers it.
    if ($event->isMainRequest() && MarkdownPathProcessor::asked($request) && !$event->hasResponse() && !$this->answersMarkdown($request)) {
      throw new NotFoundHttpException();
    }
  }

  /**
   * Charges an agent for what the route shows, or converts a free page.
   */
  private function answerAgent(RequestEvent $event, FieldableEntityInterface $entity): void {
    $request = $event->getRequest();
    // The entity's page, as HTML or as markdown: its own route, or the twins
    // Markdownify adds for it (/node/1.md, /markdownify/node/1).
    $page = $this->isPage($request);
    $markdown = $page && ($this->wantsMarkdown($request) || $request->getRequestFormat() === 'markdown');
    $here = $page ? X402PriceItem::find($entity) : NULL;
    // On its own URL: what a field sells there (its "canonical" setting);
    // asked for markdown (Accept, or .md), also the page's view mode sold at
    // its x402 URL, since that markdown is the product. Elsewhere (JSON:API,
    // REST) the route can show any field, so anything sold is charged.
    $item = $page
      ? $here ?? ($markdown ? $this->soldMarkdown($request, $entity) : NULL)
      : X402PriceItem::find($entity) ?? X402PriceItem::find($entity, self::PAGE) ?? X402PriceItem::all($entity)[0] ?? NULL;
    if ($item && $this->isAgent($request)) {
      // The resource bought: the own URL for what is sold there, else the
      // x402 URL of the product.
      $event->setResponse($this->delivery->charge($request, $entity, $item, $here !== NULL));
    }
    // Free: neither this URL nor the page's markdown is sold. The home page's
    // own page reads as the home page for agents (/es/home.md as /es.md); a
    // route that answers markdown itself (Markdownify's) converts the rest.
    elseif (!$item && $markdown && $this->agentHome->isHome($entity)) {
      $this->pageCacheKillSwitch->trigger();
      $event->setResponse($this->agentHome->response($request));
    }
    elseif (!$item && $markdown && $this->isCanonical($request)) {
      $this->pageCacheKillSwitch->trigger();
      $event->setResponse($this->delivery->markdown($entity, self::PAGE));
    }
  }

  /**
   * What a field sells of the page's markdown, if anything.
   *
   * The markdown shows a view mode: "full" here, or on Markdownify's routes
   * the one its settings give the bundle. Sold in that mode, or in one drawn
   * with the same display (Delivery::soldIn()), it is the product.
   */
  private function soldMarkdown(Request $request, FieldableEntityInterface $entity): ?X402PriceItem {
    $mode = self::PAGE;
    if (!$this->isCanonical($request)) {
      $modes = $this->configFactory->get('markdownify.settings')->get('supported_entities.' . $entity->getEntityTypeId() . '.view_modes');
      $mode = (string) ($modes[$entity->bundle()] ?? self::PAGE);
    }
    return X402PriceItem::find($entity, self::PAGE) ?? Delivery::soldIn($entity, $mode);
  }

  /**
   * The page people see: announce what is sold, keep it out of page cache.
   *
   * The page cache answers by URL alone, so a cached page would reach an
   * agent before it could be charged. Done here and not in a view hook: the
   * render cache skips view hooks on its hits.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $entity = $event->isMainRequest() ? $this->entity($request) : NULL;
    if (!$entity || !$event->getResponse()->isSuccessful()) {
      return;
    }
    $items = X402PriceItem::all($entity);
    // Any route that shows what is sold (its page, a revision, a contrib
    // route), as people see it: never cached for an agent.
    if ($items) {
      $this->pageCacheKillSwitch->trigger();
    }
    if (!$this->isCanonical($request)) {
      return;
    }
    $headers = $event->getResponse()->headers;
    // One URL, HTML or markdown by Accept: shared caches (a CDN) must know.
    // A free page's markdown is announced by X402PayHooks::pageAttachments().
    $headers->set('Vary', 'Accept', FALSE);
    foreach ($items as $item) {
      $mode = $item->viewMode();
      $paid = Url::fromRoute('x402_pay.entity', [
        'entity_type' => $entity->getEntityTypeId(),
        'entity' => $entity->id(),
        'view_mode' => $mode,
      ])->toString(TRUE)->getGeneratedUrl();
      $headers->set('Link', "<$paid>; rel=\"alternate\"; type=\"text/markdown\"; title=\"$mode\"", FALSE);
    }
  }

  /**
   * The entity a route shows, if any: its page (or its .md), an API.
   *
   * Not on forms nor admin pages (editors' tools), nor on x402's own route,
   * which sells on its own.
   */
  private function entity(Request $request): ?FieldableEntityInterface {
    $route = $request->attributes->get('_route_object');
    if (!$route || $request->attributes->get('_route') === 'x402_pay.entity' || $route->getOption('_admin_route') || $route->hasDefault('_entity_form') || $route->hasDefault('_form')) {
      return NULL;
    }
    $names = array_keys($route->getOption('parameters') ?? []);
    // Canonical routes upcast their entity without declaring it.
    if (preg_match('/^entity\.(\w+)\.canonical$/', (string) $request->attributes->get('_route'), $m)) {
      $names[] = $m[1];
    }
    foreach ($names as $name) {
      $value = $request->attributes->get($name);
      if ($value instanceof FieldableEntityInterface) {
        return $value;
      }
    }
    return NULL;
  }

  /**
   * Whether the route is an entity's own page.
   */
  private function isCanonical(Request $request): bool {
    return (bool) preg_match('/^entity\.\w+\.canonical$/', (string) $request->attributes->get('_route'));
  }

  /**
   * Whether the route is one in the markdown format, which answers itself.
   */
  private function answersMarkdown(Request $request): bool {
    $route = $request->attributes->get('_route_object');
    return $route && in_array('markdown', explode('|', (string) $route->getRequirement('_format')), TRUE);
  }

  /**
   * Whether the route shows an entity's own page, in any format.
   *
   * Its own route, or the ones Markdownify adds for it: the same path in the
   * markdown format (entity.node.canonical_markdownify) and its own prefix
   * (entity.node.markdownify, /markdownify/node/1).
   */
  private function isPage(Request $request): bool {
    return (bool) preg_match('/^entity\.\w+\.(canonical|canonical_markdownify|markdownify)$/', (string) $request->attributes->get('_route'));
  }

  /**
   * Asked for markdown by name (Accept, or .md): not a browser, not "anything".
   */
  private function wantsMarkdown(Request $request): bool {
    return str_contains((string) $request->headers->get('Accept'), 'text/markdown') || MarkdownPathProcessor::asked($request);
  }

  /**
   * An agent pays, does not ask for HTML, or names itself in User-Agent.
   *
   * Or is answered anything but an HTML page (markdown, JSON, by a route
   * or ?_format=): only a page is free for people, whatever Accept says.
   */
  public function isAgent(Request $request): bool {
    if ($request->headers->has(PaymentHeaders::SIGNATURE) || MarkdownPathProcessor::asked($request)
      || $request->getRequestFormat() !== 'html' || !str_contains((string) $request->headers->get('Accept'), 'text/html')) {
      return TRUE;
    }
    $pattern = (string) $this->configFactory->get('x402_pay.settings')->get('agent_user_agents');
    return $pattern !== '' && preg_match('~' . str_replace('~', '\~', $pattern) . '~i', (string) $request->headers->get('User-Agent')) === 1;
  }

}
