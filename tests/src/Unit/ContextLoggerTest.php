<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\ContextLogger;
use Psr\Log\AbstractLogger;

/**
 * @coversDefaultClass \Drupal\x402_pay\ContextLogger
 * @group x402_pay
 */
class ContextLoggerTest extends UnitTestCase {

  /**
   * @covers ::log
   */
  public function testContextTravelsAsPlaceholderDrupalKeeps(): void {
    $inner = new class() extends AbstractLogger {

      /**
       * Each line it was given.
       */
      public array $lines = [];

      /**
       * Keeps what it is given.
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->lines[] = [$level, $message, $context];
      }

    };
    (new ContextLogger($inner))->log('warning', 'x402 address rejected', [
      'address' => '0x1111111111111111111111111111111111111111',
      'reason' => 'address_sanctioned',
      'exception' => new \RuntimeException('node down'),
    ]);
    [$level, $message, $context] = $inner->lines[0];
    $this->assertSame('warning', $level);
    $this->assertSame('x402 address rejected: @context', $message);
    $decoded = json_decode($context['@context'], TRUE);
    $this->assertSame('address_sanctioned', $decoded['reason']);
    $this->assertSame('RuntimeException: node down', $decoded['exception']);
    $this->assertSame(['@context'], array_keys($context), 'Nothing else reaches the logger as a placeholder.');
  }

  /**
   * @covers ::log
   */
  public function testLineWithoutContextStaysAsItIs(): void {
    $inner = new class() extends AbstractLogger {

      /**
       * Each line it was given.
       */
      public array $lines = [];

      /**
       * Keeps what it is given.
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->lines[] = [$message, $context];
      }

    };
    (new ContextLogger($inner))->info('x402 intent');
    $this->assertSame([['x402 intent', []]], $inner->lines);
  }

}
