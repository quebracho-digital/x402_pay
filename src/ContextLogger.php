<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Hands a PSR-3 line to Drupal's logger with its context in the message.
 *
 * The quebracho/x402 library logs a fixed message and puts the details
 * (address, reason, network, transaction) in the context. Drupal's logger
 * keeps only the values the message names as placeholders, so those details
 * would be lost. The library promises its context holds no signature, body or
 * token.
 */
final class ContextLogger extends AbstractLogger {

  public function __construct(private readonly LoggerInterface $logger) {}

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    if (!$context) {
      $this->logger->log($level, $message);
      return;
    }
    if (($context['exception'] ?? NULL) instanceof \Throwable) {
      $context['exception'] = $context['exception']::class . ': ' . $context['exception']->getMessage();
    }
    $this->logger->log($level, $message . ': @context', ['@context' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)]);
  }

}
