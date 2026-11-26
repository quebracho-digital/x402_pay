<?php

declare(strict_types=1);

namespace Drupal\x402_pay_home_test\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * A page of a module, as HTML and as markdown.
 */
final class PageController {

  /**
   * The page as people see it.
   */
  public function html(): array {
    return ['#markup' => 'Test page in HTML'];
  }

  /**
   * The page as an agent reads it.
   */
  public function markdown(): Response {
    return new Response("# Test page\n", 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
  }

}
