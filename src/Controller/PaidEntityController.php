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
 * /x402/{entity_type}/{entity}/{view_mode}: one product, for whoever pays.
 *
 * Without a view mode: the product sold on the entity's own URL.
 */
final class PaidEntityController implements ContainerInjectionInterface {

  public function __construct(private readonly Delivery $delivery) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('x402_pay.delivery'));
  }

  public function __invoke(Request $request, EntityInterface $entity, string $view_mode = ''): Response {
    $item = X402PriceItem::find($entity, $view_mode === '' ? NULL : $view_mode) ?? throw new NotFoundHttpException();
    return $this->delivery->charge($request, $entity, $item, $view_mode === '');
  }

}
