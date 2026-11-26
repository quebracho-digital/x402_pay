<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Routing;

use Drupal\Core\Routing\FilterInterface;
use Drupal\x402_pay\PathProcessor\MarkdownPathProcessor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouteCollection;

/**
 * A ".md" path asks for the markdown format, as Markdownify reads it.
 *
 * So a module answers its own page's ".md" the way it would with
 * Markdownify: the same path, required in the markdown format. Without
 * Markdownify only (X402PayServiceProvider): it does this itself.
 */
final class MarkdownFormatRouteFilter implements FilterInterface {

  /**
   * {@inheritdoc}
   */
  public function filter(RouteCollection $collection, Request $request): RouteCollection {
    if (MarkdownPathProcessor::asked($request) && $request->getRequestFormat(NULL) === NULL) {
      $request->setRequestFormat('markdown');
    }
    return $collection;
  }

}
