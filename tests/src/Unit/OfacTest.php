<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\State;
use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\Ofac;
use Psr\Log\AbstractLogger;
use Quebracho\X402\ListSync;
use Quebracho\X402\SyncResult;

/**
 * @coversDefaultClass \Drupal\x402_pay\Ofac
 * @group x402_pay
 */
class OfacTest extends UnitTestCase {

  private const LISTED = '0x1111111111111111111111111111111111111111';

  /**
   * A temporary directory standing for the private files path.
   */
  private string $dir;

  /**
   * The clock, in seconds.
   */
  private int $now = 1_800_000_000;

  /**
   * A logger that keeps its lines.
   */
  private object $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->dir = sys_get_temp_dir() . '/x402_pay_ofac_' . bin2hex(random_bytes(4));
    $this->logger = new class() extends AbstractLogger {

      /**
       * Level, message and context of each line.
       */
      public array $lines = [];

      /**
       * Records what the logger is given.
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->lines[] = [$level, (string) $message, $context];
      }

    };
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (glob($this->dir . '/x402_pay/*') ?: [] as $file) {
      unlink($file);
    }
    @rmdir($this->dir . '/x402_pay');
    @rmdir($this->dir);
    parent::tearDown();
  }

  /**
   * A ListSync that writes a snapshot listing LISTED, or throws.
   */
  private function sync(bool $fails = FALSE): ListSync {
    return new class($fails) implements ListSync {

      /**
       * How many times it was asked.
       */
      public int $calls = 0;

      public function __construct(private readonly bool $fails) {}

      /**
       * Writes a snapshot that lists the address.
       */
      public function sync(string $file): SyncResult {
        $this->calls++;
        if ($this->fails) {
          throw new \RuntimeException('OFAC SDN: HTTP 503.');
        }
        file_put_contents($file, json_encode([
          'source' => 'test',
          'published' => '2999-01-01T00:00:00Z',
          'addresses' => ['ETH' => ['0x1111111111111111111111111111111111111111']],
        ]));
        return new SyncResult(1, '2999-01-01T00:00:00Z', TRUE);
      }

    };
  }

  /**
   * The Ofac under test, over a fake clock and a fake download.
   */
  private function ofac(ListSync $sync): Ofac {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn() => $this->now);
    $state = new State(new KeyValueMemoryFactory(), $this->createMock(CacheBackendInterface::class), $this->createMock(LockBackendInterface::class));
    $settings = new Settings(['file_private_path' => $this->dir]);
    return new Ofac($state, $time, $this->logger, $sync, $settings, 'sites/default');
  }

  /**
   * @covers ::refresh
   * @covers ::list
   */
  public function testTheSnapshotItWritesIsTheListTheSaleReads(): void {
    $sync = $this->sync();
    $ofac = $this->ofac($sync);
    $this->assertFalse($ofac->list()->listed(self::LISTED), 'The shipped list does not name it.');
    $ofac->refresh();
    $this->assertSame(1, $sync->calls);
    $this->assertStringStartsWith($this->dir . '/x402_pay/', $ofac->snapshot());
    $this->assertTrue($this->ofac($sync)->list()->listed(self::LISTED), 'A newer snapshot wins over the shipped list.');
  }

  /**
   * @covers ::refresh
   */
  public function testItAsksOfacOncePerDay(): void {
    $sync = $this->sync();
    $ofac = $this->ofac($sync);
    $ofac->refresh();
    $this->now += Ofac::INTERVAL - 1;
    $ofac->refresh();
    $this->assertSame(1, $sync->calls);
    $this->now += 1;
    $ofac->refresh();
    $this->assertSame(2, $sync->calls);
  }

  /**
   * @covers ::refresh
   */
  public function testFailedDownloadIsLoggedAndRetriedSoon(): void {
    $sync = $this->sync(TRUE);
    $ofac = $this->ofac($sync);
    $ofac->refresh();
    $this->assertSame('warning', $this->logger->lines[0][0]);
    $this->assertStringContainsString('HTTP 503', $this->logger->lines[0][2]['@error']);
    $this->now += Ofac::RETRY - 1;
    $ofac->refresh();
    $this->assertSame(1, $sync->calls, 'Not before the retry time.');
    $this->now += 1;
    $ofac->refresh();
    $this->assertSame(2, $sync->calls, 'Retried within the hour, not tomorrow.');
  }

}
