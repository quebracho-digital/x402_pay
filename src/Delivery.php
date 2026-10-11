<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use League\HTMLToMarkdown\HtmlConverter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sells an entity with an x402_price field: charges, then hands it over.
 */
class Delivery {

  public function __construct(
    private readonly PaymentGate $gate,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
    #[Autowire(service: 'logger.channel.x402_pay')]
    private readonly LoggerInterface $logger,
  ) {}

  public function charge(Request $request, FieldableEntityInterface $entity, X402PriceItem $price): Response {
    try {
      $requirements = $price->requirements();
      $seller = $price->seller();
    }
    catch (\RuntimeException | \InvalidArgumentException $e) {
      $this->logger->error('Cannot sell @type @id: @message', ['@type' => $entity->getEntityTypeId(), '@id' => $entity->id(), '@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Not for sale right now'], 503);
    }
    $resource = [
      'url' => $entity->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'description' => (string) $entity->label(),
      'mimeType' => 'text/markdown',
    ];
    return $this->gate->handle($request, $requirements, $resource, $seller, fn() => $this->markdown($entity));
  }

  /**
   * The entity's full display as markdown, under its label as the title.
   */
  public function markdown(FieldableEntityInterface $entity): Response {
    $build = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId())->view($entity, 'full');
    $html = (string) $this->renderer->renderInIsolation($build);
    $body = (new HtmlConverter(['strip_tags' => TRUE, 'remove_nodes' => 'script style', 'hard_break' => TRUE]))->convert($html);
    return new Response(trim('# ' . $entity->label() . "\n\n" . $body) . "\n", 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
  }

}
