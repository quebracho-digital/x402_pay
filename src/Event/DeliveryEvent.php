<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Event;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * What a sale delivers, once the payment verified: set a response to replace
 * the view mode's markdown, e.g. with a service called with the request's
 * input.
 *
 * Runs before settling: a response other than 2xx is never charged.
 */
final class DeliveryEvent extends Event {

  private ?Response $response = NULL;

  public function __construct(
    public readonly FieldableEntityInterface $entity,
    public readonly X402PriceItem $item,
    public readonly string $viewMode,
    public readonly Request $request,
  ) {}

  public function setResponse(Response $response): void {
    $this->response = $response;
    $this->stopPropagation();
  }

  public function getResponse(): ?Response {
    return $this->response;
  }

}
