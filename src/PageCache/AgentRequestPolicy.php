<?php

declare(strict_types=1);

namespace Drupal\x402_pay\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\x402_pay\PathProcessor\MarkdownPathProcessor;
use Quebracho\X402\PaymentHeaders;
use Symfony\Component\HttpFoundation\Request;

/**
 * The page cache never answers a request for markdown, nor one that pays.
 *
 * A .md path asks for markdown too, before routing has said so
 * (MarkdownPathProcessor).
 *
 * It keys by URL alone: without this, the HTML a browser left in it would
 * answer an agent asking the same URL for markdown, before the gate
 * (AgentRequestSubscriber) could charge it or convert the page.
 */
final class AgentRequestPolicy implements RequestPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function check(Request $request): ?string {
    return $request->headers->has(PaymentHeaders::SIGNATURE)
      || str_contains((string) $request->headers->get('Accept'), 'text/markdown')
      || MarkdownPathProcessor::asked($request) ? self::DENY : NULL;
  }

}
