<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\x402_pay\Event\DeliveryEvent;
use Drupal\x402_pay\Event\PriceEvent;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use League\HTMLToMarkdown\HtmlConverter;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sells what an x402_price field offers: prices it, charges, hands it over.
 */
class Delivery {

  public function __construct(
    private readonly PaymentGate $gate,
    private readonly RendererInterface $renderer,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly AccountInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Sells one product of an entity: 402, then the delivery once paid.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The buyer's request.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity sold.
   * @param \Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem $item
   *   The field item that sells it.
   * @param bool $canonical
   *   Sold on the entity's own URL: that is the resource bought.
   */
  public function charge(Request $request, FieldableEntityInterface $entity, X402PriceItem $item, bool $canonical): Response {
    $event = $this->quote($request, $entity, $item);
    if ($event->isDenied()) {
      return new JsonResponse(['error' => 'Not for sale to you'], 403);
    }
    $deliver = fn() => $this->deliver($request, $entity, $item);
    if ($event->isFree()) {
      return $deliver();
    }
    try {
      // The event's seller, or its address alone; else the field's.
      $seller = $event->payTo() !== NULL ? $event->seller() : $item->seller();
      $requirements = $this->requirements($event, $item);
    }
    catch (\RuntimeException | \InvalidArgumentException $e) {
      $this->logger->error('Cannot sell @type @id: @message', [
        '@type' => $entity->getEntityTypeId(),
        '@id' => $entity->id(),
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'Not for sale right now'], 503);
    }
    $url = $canonical
      ? $entity->toUrl('canonical', ['absolute' => TRUE])
      : Url::fromRoute('x402_pay.entity', [
        'entity_type' => $entity->getEntityTypeId(),
        'entity' => $entity->id(),
        'view_mode' => $item->viewMode(),
      ], ['absolute' => TRUE, 'language' => $entity->language()]);
    $resource = [
      'url' => $url->toString(TRUE)->getGeneratedUrl(),
      'description' => (string) $entity->label(),
      'mimeType' => $event->mimeType(),
      // For the log only (PaymentGate): not sent to the buyer.
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (string) $entity->id(),
    ];
    return $this->gate->handle($request, $requirements, $resource, $seller, $deliver);
  }

  /**
   * What this sale costs, to whom it is paid, or whether it is not for sale.
   *
   * The field's answer, after the price event. The sale and the catalogue
   * both ask here, so the catalogue announces what the sale will charge.
   */
  public function quote(Request $request, FieldableEntityInterface $entity, X402PriceItem $item): PriceEvent {
    return $this->eventDispatcher->dispatch(new PriceEvent(
      $entity, $item, $item->viewMode(), $request, $this->currentUser, $item->price(),
      $this->currentUser->hasPermission('access x402 content free'),
    ));
  }

  /**
   * The PaymentRequirements of a quote.
   *
   * @throws \RuntimeException|\InvalidArgumentException
   *   No usable seller, or a price the network cannot charge.
   */
  public function requirements(PriceEvent $quote, X402PriceItem $item): array {
    $requirements = Networks::requirements($quote->network() ?? (string) $item->getFieldDefinition()->getSetting('network'), $quote->price(), $quote->payTo() ?? $item->payTo(), $quote->timeout());
    if ($quote->settlesFirst()) {
      // Announced: a client must know its money moves before the delivery.
      $requirements['extra']['paymentFlow'] = 'upfront';
    }
    return $requirements;
  }

  /**
   * What is sold: whatever a DeliveryEvent subscriber answers, else markdown.
   */
  private function deliver(Request $request, FieldableEntityInterface $entity, X402PriceItem $item): Response {
    $event = $this->eventDispatcher->dispatch(new DeliveryEvent($entity, $item, $item->viewMode(), $request));
    return $event->getResponse() ?? $this->markdown($entity, $item->viewMode());
  }

  /**
   * The fields of a view mode as markdown, under the entity's label.
   *
   * Only what the site builder chose in that display: not the entity's
   * template, nor the fields a display cannot configure (a node's title,
   * author and date, which the template shows), nor the price itself.
   */
  public function markdown(FieldableEntityInterface $entity, string $viewMode): Response {
    $build = EntityViewDisplay::collectRenderDisplay($entity, $viewMode)->build($entity);
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() === 'x402_price' || !$definition->isDisplayConfigurable('view')) {
        unset($build[$name]);
      }
    }
    $html = (string) $this->renderer->renderInIsolation($build);
    $body = (new HtmlConverter(['strip_tags' => TRUE, 'remove_nodes' => 'script style', 'hard_break' => TRUE]))->convert($html);
    // Field wrappers leave runs of blank (or space-only) lines: one is enough.
    $body = preg_replace('/\n(?:[ \t\x{a0}]*\n){2,}/u', "\n\n", $body);
    return new Response(trim('# ' . $entity->label() . "\n\n" . $body) . "\n", 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
  }

}
