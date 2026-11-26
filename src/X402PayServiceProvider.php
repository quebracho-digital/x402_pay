<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * With Markdownify, the ".md" paths are its own.
 *
 * Markdownify turns /node/1.md into /markdownify/node/1 and answers it with
 * its own routes, which the gate charges as the entity's page. Ours, which
 * runs first, would take the ".md" off before Markdownify sees it: two ways
 * of reading one path, one winning by priority. Without Markdownify ours
 * stays, with the filter that gives a ".md" the markdown format, and the
 * gate converts free pages itself.
 */
final class X402PayServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (isset($container->getParameter('container.modules')['markdownify'])) {
      $container->removeDefinition('x402_pay.markdown_path_processor');
      $container->removeDefinition('x402_pay.markdown_format_route_filter');
    }
  }

}
