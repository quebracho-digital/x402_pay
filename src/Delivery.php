<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
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
   * @param bool $canonical
   *   Sold on the entity's own URL: that is the resource bought.
   */
  public function charge(Request $request, FieldableEntityInterface $entity, X402PriceItem $item, bool $canonical): Response {
    $viewMode = $item->viewMode();
    $event = $this->eventDispatcher->dispatch(new PriceEvent(
      $entity, $item, $viewMode, $request, $this->currentUser, $item->price(),
      $this->currentUser->hasPermission('access x402 content free'),
    ));
    if ($event->isDenied()) {
      return new JsonResponse(['error' => 'Not for sale to you'], 403);
    }
    if ($event->isFree()) {
      return $this->markdown($entity, $viewMode);
    }
    try {
      $requirements = $item->requirements($event->price());
      $seller = $item->seller();
    }
    catch (\RuntimeException | \InvalidArgumentException $e) {
      $this->logger->error('Cannot sell @type @id: @message', ['@type' => $entity->getEntityTypeId(), '@id' => $entity->id(), '@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Not for sale right now'], 503);
    }
    $url = $canonical
      ? $entity->toUrl('canonical', ['absolute' => TRUE])
      : Url::fromRoute('x402_pay.entity', ['entity_type' => $entity->getEntityTypeId(), 'entity' => $entity->id(), 'view_mode' => $viewMode], ['absolute' => TRUE, 'language' => $entity->language()]);
    $resource = [
      'url' => $url->toString(TRUE)->getGeneratedUrl(),
      'description' => (string) $entity->label(),
      'mimeType' => 'text/markdown',
    ];
    return $this->gate->handle($request, $requirements, $resource, $seller, fn() => $this->markdown($entity, $viewMode));
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
    return new Response(trim('# ' . $entity->label() . "\n\n" . $body) . "\n", 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
  }

}
