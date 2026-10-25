<?php

declare(strict_types=1);

namespace Drupal\x402_pay\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Any page plus ".md" is that page asked for as markdown.
 *
 * For agents that cannot send "Accept: text/markdown" or never read a Link
 * header: /terms.md, /es/terms.md, /node/1.md. It runs after the language
 * prefix is taken off and before aliases are resolved; the gate
 * (AgentRequestSubscriber) then charges or converts as for that Accept,
 * and answers 404 rather than an HTML page under a .md name.
 *
 * ponytail: every path ending in .md is taken as asked for markdown, so a
 * path alias or route of its own ending in .md is not reachable; check the
 * alias manager here if a site needs one.
 */
final class MarkdownPathProcessor implements InboundPathProcessorInterface {

  /**
   * Whether the request's own path ends in .md.
   *
   * Asked of the path as sent, not of this processor: routing caches what
   * it resolved per path and skips processors on the next request.
   */
  public static function asked(Request $request): bool {
    return str_ends_with($request->getPathInfo(), '.md');
  }

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request): string {
    return strlen($path) > 4 && str_ends_with($path, '.md') ? substr($path, 0, -3) : $path;
  }

}
