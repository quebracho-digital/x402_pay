<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\x402_pay\Delivery;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /x402/{entity_type}/{entity}: the entity for whoever pays, people included.
 */
final class PaidEntityController implements ContainerInjectionInterface {

  public function __construct(private readonly Delivery $delivery) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('x402_pay.delivery'));
  }

  public function __invoke(Request $request, EntityInterface $entity): Response {
    $price = X402PriceItem::find($entity) ?? throw new NotFoundHttpException();
    return $this->delivery->charge($request, $entity, $price);
  }

}
