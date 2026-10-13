<?php

declare(strict_types=1);

namespace Drupal\x402_pay\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Url;
use Drupal\x402_pay\Delivery;
use Drupal\x402_pay\PaymentHeaders;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The one gate for what agents read: charged when sold, markdown when free.
 *
 * It decides by entity, not by route or format: any route that carries an
 * entity it sells (its page, a .md path, ?_format=, JSON:API, REST) answers
 * agents with the sale, so no other module can hand out for free what is
 * sold. Browsers still get the page. It runs after routing's access check:
 * what a visitor cannot see is never offered.
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
    if (!$entity) {
      return;
    }
    $canonical = $this->isCanonical($request);
    // On its own URL: only what a field sells there (its "canonical"
    // setting). Elsewhere (JSON:API, REST, a .md path) the route can show
    // any field, so anything sold is charged.
    $item = $canonical
      ? X402PriceItem::find($entity)
      : X402PriceItem::find($entity) ?? X402PriceItem::find($entity, self::PAGE) ?? X402PriceItem::all($entity)[0] ?? NULL;
    if ($item && $this->isAgent($request)) {
      $event->setResponse($this->delivery->charge($request, $entity, $item, $canonical));
    }
    // Free: nothing sold on this URL, and the page's view mode not sold
    // elsewhere either.
    elseif (!$item && $canonical && $this->wantsMarkdown($request) && !X402PriceItem::find($entity, self::PAGE)) {
      $this->pageCacheKillSwitch->trigger();
      $event->setResponse($this->delivery->markdown($entity, self::PAGE));
    }
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
    $entity = $event->isMainRequest() && $this->isCanonical($request) ? $this->entity($request) : NULL;
    if (!$entity || !$event->getResponse()->isSuccessful()) {
      return;
    }
    $headers = $event->getResponse()->headers;
    // One URL, HTML or markdown by Accept: shared caches (a CDN) must know.
    $headers->set('Vary', 'Accept', FALSE);
    $items = X402PriceItem::all($entity);
    if (!$items) {
      if (!str_starts_with((string) $headers->get('Content-Type'), 'text/markdown')) {
        $self = $entity->toUrl()->toString(TRUE)->getGeneratedUrl();
        $headers->set('Link', "<$self>; rel=\"alternate\"; type=\"text/markdown\"", FALSE);
      }
      return;
    }
    $this->pageCacheKillSwitch->trigger();
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
   * The entity a route shows, if any: its page, a .md path, an API.
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

  private function isCanonical(Request $request): bool {
    return (bool) preg_match('/^entity\.\w+\.canonical$/', (string) $request->attributes->get('_route'));
  }

  /**
   * Asked for markdown by name: not a browser, not "anything".
   */
  private function wantsMarkdown(Request $request): bool {
    return str_contains((string) $request->headers->get('Accept'), 'text/markdown');
  }

  /**
   * An agent pays, does not ask for HTML, or names itself in User-Agent.
   */
  public function isAgent(Request $request): bool {
    if ($request->headers->has(PaymentHeaders::SIGNATURE) || !str_contains((string) $request->headers->get('Accept'), 'text/html')) {
      return TRUE;
    }
    $pattern = (string) $this->configFactory->get('x402_pay.settings')->get('agent_user_agents');
    return $pattern !== '' && preg_match('~' . str_replace('~', '\~', $pattern) . '~i', (string) $request->headers->get('User-Agent')) === 1;
  }

}
