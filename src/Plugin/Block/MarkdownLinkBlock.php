<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\x402_pay\Delivery;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A link to this page as markdown, where agents look for one.
 *
 * Shown only on a free entity's own page. Its markup is the
 * x402-pay-markdown-link template, for a theme to override.
 */
#[Block(
  id: 'x402_pay_markdown_link',
  admin_label: new TranslatableMarkup('Markdown for agents'),
  category: new TranslatableMarkup('x402 Pay'),
)]
final class MarkdownLinkBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected RouteMatchInterface $routeMatch) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('current_route_match'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $cacheability = new CacheableMetadata();
    $url = Delivery::markdownUrl($this->routeMatch, $cacheability);
    $build = $url ? ['#theme' => 'x402_pay_markdown_link', '#url' => $url] : [];
    $cacheability->applyTo($build);
    return $build;
  }

}
