<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\Stop;
use Drupal\x402_pay\Event\StopEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * The stop switch: two switches, either of which pauses.
 *
 * @coversDefaultClass \Drupal\x402_pay\Stop
 * @group x402_pay
 */
class StopTest extends UnitTestCase {

  /**
   * A temporary directory standing for the private files path.
   */
  private string $dir;

  /**
   * The state, as an array: what the module's switch writes.
   */
  private array $stored = [];

  /**
   * Whether the state throws, as a database that is down does.
   */
  private bool $stateDown = FALSE;

  /**
   * What was written, in order: 'file' or 'state'.
   */
  private array $order = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->dir = sys_get_temp_dir() . '/x402_pay_stop_' . bin2hex(random_bytes(4));
    mkdir($this->dir, 0700);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    exec('chmod -R u+rwx ' . escapeshellarg($this->dir) . ' && rm -rf ' . escapeshellarg($this->dir));
    parent::tearDown();
  }

  /**
   * The switch, on a state kept in this test and files under its directory.
   */
  private function stop(array $settings = []): Stop {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(function (string $key) {
      if ($this->stateDown) {
        throw new \RuntimeException('database is down');
      }
      return $this->stored[$key] ?? NULL;
    });
    $state->method('set')->willReturnCallback(function (string $key, mixed $value): void {
      if ($this->stateDown) {
        throw new \RuntimeException('database is down');
      }
      // The library's flag is already there when the module's is written.
      $this->order[] = is_file($this->flag()) ? 'state after file' : 'state without file';
      $this->stored[$key] = $value;
    });
    $state->method('delete')->willReturnCallback(function (string $key): void {
      if ($this->stateDown) {
        throw new \RuntimeException('database is down');
      }
      unset($this->stored[$key]);
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1_800_000_000);
    return new Stop($state, $time, new NullLogger(), new Settings($settings + ['file_private_path' => $this->dir]), 'sites/default');
  }

  /**
   * Where the library's flag is, with the settings of stop().
   */
  private function flag(): string {
    return $this->dir . '/x402_pay/stop/paused';
  }

  /**
   * Pausing sets the library's switch, then the module's; resuming, both.
   *
   * @covers ::engage
   * @covers ::release
   * @covers ::layers
   * @covers ::note
   */
  public function testPausesBothSwitchesTheLibrarysFirstAndResumesBoth(): void {
    $stop = $this->stop();
    $this->assertFalse($stop->engaged());
    $this->assertSame(['library' => FALSE, 'module' => FALSE], $stop->layers());
    $this->assertNull($stop->note());

    $this->assertSame(['library' => NULL, 'module' => NULL], $stop->engage('facilitator incident'));
    $this->assertSame(['state after file'], $this->order, 'The library first.');
    $this->assertSame(['library' => TRUE, 'module' => TRUE], $stop->layers());
    $this->assertTrue($stop->engaged());
    $this->assertSame(['time' => 1_800_000_000, 'why' => 'facilitator incident', 'uid' => 0], $stop->note());
    // The reason stays in the database: the file may be under public files.
    $this->assertStringNotContainsString('facilitator incident', (string) file_get_contents($this->flag()));

    $this->assertSame(['library' => NULL, 'module' => NULL], $stop->release());
    $this->assertSame(['library' => FALSE, 'module' => FALSE], $stop->layers());
    $this->assertFalse($stop->engaged());
    $this->assertNull($stop->note());
    // The directory goes with the flag: another system user would read one it
    // cannot search as engaged.
    $this->assertDirectoryDoesNotExist(dirname($this->flag()));
    // Resuming what is running is not an error.
    $this->assertSame(['library' => NULL, 'module' => NULL], $stop->release());
  }

  /**
   * One switch alone pauses, whichever it is.
   *
   * @covers ::engaged
   * @covers ::layers
   */
  public function testEitherSwitchAlonePauses(): void {
    $stop = $this->stop();
    // The emergency way, with no Drupal at hand: touch.
    mkdir(dirname($this->flag()), 0700, TRUE);
    touch($this->flag());
    $this->assertSame(['library' => TRUE, 'module' => FALSE], $stop->layers());
    $this->assertTrue($stop->engaged());
    $this->assertNull($stop->note(), 'Nobody said why.');
    $stop->release();
    $this->assertFalse($stop->engaged());

    // The database's alone: another web head set it, and this host has no flag.
    $this->stored['x402_pay.stopped'] = ['time' => 1, 'why' => 'from another host', 'uid' => 7];
    $this->assertSame(['library' => FALSE, 'module' => TRUE], $stop->layers());
    $this->assertTrue($stop->engaged());
    $this->assertSame(['time' => 1, 'why' => 'from another host', 'uid' => 7], $stop->note());
    $stop->release();
    $this->assertFalse($stop->engaged());
  }

  /**
   * A flag file that cannot be set is said, and the module's switch pauses.
   *
   * @covers ::engage
   */
  public function testTheModulesSwitchPausesWhenTheLibrarysCannotBeSet(): void {
    $stop = $this->stop();
    // A directory others can write to: the library refuses a flag anyone
    // could remove.
    mkdir(dirname($this->flag()), 0777, TRUE);
    chmod(dirname($this->flag()), 0777);

    $failed = $stop->engage('a key we no longer trust');
    $this->assertNotNull($failed['library']);
    $this->assertNull($failed['module']);
    $this->assertSame(['library' => FALSE, 'module' => TRUE], $stop->layers());
    $this->assertTrue($stop->engaged(), 'Paused all the same: that is what the second switch is for.');
  }

  /**
   * A database that is down is said, and the library's switch pauses.
   *
   * @covers ::engage
   * @covers ::layers
   */
  public function testTheLibrarysSwitchPausesWhenTheDatabaseIsDown(): void {
    $stop = $this->stop();
    $this->stateDown = TRUE;
    // A state that cannot be read counts as engaged: unsure, the site does
    // not sell.
    $this->assertSame(['library' => FALSE, 'module' => TRUE], $stop->layers());

    $failed = $stop->engage();
    $this->assertNull($failed['library']);
    $this->assertSame('database is down', $failed['module']);
    $this->assertFileExists($this->flag());

    $this->stateDown = FALSE;
    $this->assertSame(['library' => TRUE, 'module' => FALSE], $stop->layers(), 'The database is back, and the flag still pauses.');
    $this->assertTrue($stop->engaged());
  }

  /**
   * A flag that cannot be cleared keeps the site paused, and says so.
   *
   * @covers ::release
   */
  public function testFlagThatCannotBeClearedIsReported(): void {
    $stop = $this->stop();
    $stop->engage();
    // Something that is no file sits at the path (the library counts it as
    // engaged, and will not remove a directory).
    unlink($this->flag());
    mkdir($this->flag());

    $failed = $stop->release();
    $this->assertNotNull($failed['library']);
    $this->assertNull($failed['module']);
    $this->assertSame(['library' => TRUE, 'module' => FALSE], $stop->layers());
    $this->assertTrue($stop->engaged(), 'Still paused: the report is not a guess.');
  }

  /**
   * Every pause and resume is logged and told, with what failed.
   *
   * @covers ::engage
   * @covers ::release
   */
  public function testEveryUseIsLoggedAndDispatched(): void {
    $logger = new class() extends AbstractLogger {

      /**
       * Level and message of each line, placeholders filled.
       */
      public array $lines = [];

      /**
       * Records what the logger is given.
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->lines[] = $level . ': ' . strtr((string) $message, $context);
      }

    };
    $events = [];
    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$events) {
      $events[] = $event;
      // A listener that fails (a chat that is down) must not undo the pause.
      throw new \RuntimeException('chat is down');
    });
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn(string $key) => $this->stored[$key] ?? NULL);
    $state->method('set')->willReturnCallback(function (string $key, mixed $value): void {
      $this->stored[$key] = $value;
    });
    $state->method('delete')->willReturnCallback(function (string $key): void {
      unset($this->stored[$key]);
    });
    $stop = new Stop($state, $this->createMock(TimeInterface::class), $logger, new Settings(['file_private_path' => $this->dir]), 'sites/default', NULL, $dispatcher);

    $stop->engage('a price set by mistake');
    $this->assertTrue($stop->engaged(), 'Paused, whatever the listener did.');
    $this->assertSame('warning: x402 payments paused by user 0: a price set by mistake', $logger->lines[0]);
    $this->assertSame('error: A listener of the x402 stop switch failed: chat is down', $logger->lines[1]);
    $this->assertInstanceOf(StopEvent::class, $events[0]);
    $this->assertSame([TRUE, TRUE, ['library' => TRUE, 'module' => TRUE], 'a price set by mistake', 0], [
      $events[0]->pausing,
      $events[0]->engaged,
      $events[0]->layers,
      $events[0]->why,
      $events[0]->uid,
    ]);
    $this->assertSame(['library' => NULL, 'module' => NULL], $events[0]->failed);

    $stop->release();
    $this->assertSame('notice: x402 payments resumed by user 0', $logger->lines[2]);
    $this->assertSame([FALSE, FALSE], [$events[1]->pausing, $events[1]->engaged]);

    // A switch that could not be set is in the line and in the event.
    mkdir(dirname($this->flag()), 0777, TRUE);
    chmod(dirname($this->flag()), 0777);
    $stop->engage();
    $this->assertStringStartsWith('warning: x402 payments paused by user 0: (no reason given) (library: ', $logger->lines[4]);
    $this->assertNotNull($events[2]->failed['library']);
    $this->assertSame(['library' => FALSE, 'module' => TRUE], $events[2]->layers);
    $this->assertTrue($events[2]->engaged);
  }

  /**
   * The flag file can be put elsewhere, e.g. on a path web heads share.
   *
   * @covers ::file
   */
  public function testTheFlagFileFollowsSettings(): void {
    $this->assertSame($this->flag(), $this->stop()->file());
    $this->assertSame('/shared/x402/paused', $this->stop(['x402_pay_stop_file' => '/shared/x402/paused'])->file());
    // No private files directory: under the public one.
    $public = new Stop($this->createMock(StateInterface::class), $this->createMock(TimeInterface::class), new NullLogger(), new Settings([]), 'sites/default');
    $this->assertStringEndsWith('sites/default/files/x402_pay/stop/paused', $public->file());
    // A relative private files path, as many sites set it, is read from
    // Drupal's root: left relative, the library would refuse it and the site
    // would read as paused for ever.
    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', '/var/www/drupal');
    }
    $relative = new Stop($this->createMock(StateInterface::class), $this->createMock(TimeInterface::class), new NullLogger(), new Settings(['file_private_path' => '../private']), 'sites/default');
    $this->assertSame(DRUPAL_ROOT . '/../private/x402_pay/stop/paused', $relative->file());
    $this->assertFalse($relative->layers()['library']);
    // A relative path is not the same place for the web and for Drush: the
    // library refuses it, and what cannot be read counts as engaged.
    $this->assertTrue($this->stop(['x402_pay_stop_file' => 'relative/paused'])->layers()['library']);
  }

}
