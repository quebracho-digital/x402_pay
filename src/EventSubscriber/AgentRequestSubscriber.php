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
 * Charges agents on an entity's own URL; browsers still get the page.
 */
final class AgentRequestSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly Delivery $delivery,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KillSwitch $pageCacheKillSwitch,
  ) {}

  public static function getSubscribedEvents(): array {
    // After routing and its access check (32), before the dynamic page
    // cache could answer with the HTML page (27).
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
      KernelEvents::RESPONSE => ['onResponse'],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    $entity = $event->isMainRequest() ? $this->paidEntity($event->getRequest()) : NULL;
    if ($entity && $this->isAgent($event->getRequest())) {
      $event->setResponse($this->delivery->charge($event->getRequest(), $entity, X402PriceItem::find($entity)));
    }
  }

  /**
   * The page people see: announce the paid route, keep it out of page cache.
   *
   * The page cache answers by URL alone, so a cached page would reach an
   * agent before it could be charged. Done here and not in a view hook: the
   * render cache skips view hooks on its hits.
   */
  public function onResponse(ResponseEvent $event): void {
    $entity = $event->isMainRequest() ? $this->paidEntity($event->getRequest()) : NULL;
    if ($entity && $event->getResponse()->isSuccessful()) {
      $this->pageCacheKillSwitch->trigger();
      $paid = Url::fromRoute('x402_pay.entity', ['entity_type' => $entity->getEntityTypeId(), 'entity' => $entity->id()])->toString(TRUE)->getGeneratedUrl();
      $event->getResponse()->headers->set('Link', "<$paid>; rel=\"alternate\"; type=\"text/markdown\"", FALSE);
    }
  }

  /**
   * The payable entity of a canonical route, if that is the request.
   */
  private function paidEntity(Request $request): ?FieldableEntityInterface {
    if (!preg_match('/^entity\.(\w+)\.canonical$/', (string) $request->attributes->get('_route'), $m)) {
      return NULL;
    }
    $entity = $request->attributes->get($m[1]);
    return $entity instanceof FieldableEntityInterface && X402PriceItem::find($entity) ? $entity : NULL;
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
