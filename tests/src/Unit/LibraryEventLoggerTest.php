<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\EventSubscriber\LibraryEventLogger;
use Psr\Log\AbstractLogger;
use Quebracho\X402\AddressRejected;
use Quebracho\X402\PaymentLog;

/**
 * @coversDefaultClass \Drupal\x402_pay\EventSubscriber\LibraryEventLogger
 * @group x402_pay
 */
class LibraryEventLoggerTest extends UnitTestCase {

  /**
   * @covers ::getSubscribedEvents
   */
  public function testItListensToEveryEventTheLibraryLogs(): void {
    $subscribed = LibraryEventLogger::getSubscribedEvents();
    $this->assertSame(array_keys(PaymentLog::events()), array_keys($subscribed));
    $subscriber = new LibraryEventLogger(new PaymentLog(new class() extends AbstractLogger {

      /**
       * Ignores everything.
       */
      public function log($level, string|\Stringable $message, array $context = []): void {}

    }));
    foreach ($subscribed as $class => $listener) {
      $this->assertTrue(is_callable([$subscriber, $listener[0]]), "$class has no method {$listener[0]}.");
    }
  }

  /**
   * @covers ::rejected
   */
  public function testSanctionedPayerLeavesLogLine(): void {
    $logger = new class() extends AbstractLogger {

      /**
       * Each line it was given.
       */
      public array $lines = [];

      /**
       * Keeps what it is given.
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->lines[] = [$level, (string) $message, $context];
      }

    };
    $subscriber = new LibraryEventLogger(new PaymentLog($logger));
    $subscriber->rejected(new AddressRejected('payer', 'eip155:8453', '0x1111111111111111111111111111111111111111', 'address_sanctioned'));
    $this->assertSame('warning', $logger->lines[0][0]);
    $this->assertSame('address_sanctioned', $logger->lines[0][2]['reason']);
  }

}
