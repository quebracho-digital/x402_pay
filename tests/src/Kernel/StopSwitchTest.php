<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\KernelTests\KernelTestBase;
use Drupal\x402_pay\Buyer;
use Drupal\x402_pay\PaymentGate;
use Drupal\x402_pay\Stop;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Signer;
use Quebracho\X402\Stop as LibraryStop;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The stop switch on a real site: what a sale and a purchase do while paused.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class StopSwitchTest extends KernelTestBase {

  private const PAY_TO = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';

  /**
   * Anvil's first key: holds nothing anywhere real.
   */
  private const BUYER = '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['x402_pay', 'key', 'system', 'user'];

  /**
   * How many times the paid handler ran.
   */
  private int $delivered = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('x402_pay', ['x402_pay_log', 'x402_pay_claim']);
    $this->installConfig(['x402_pay']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // The flag lives outside the test database.
    $this->container->get('x402_pay.stop')->release();
    parent::tearDown();
  }

  /**
   * A sale through the gate: unpaid, or with something in the payment header.
   */
  private function sale(?PaymentGate $gate = NULL, bool $paying = FALSE): HttpResponse {
    $server = $paying ? ['HTTP_' . str_replace('-', '_', strtoupper(PaymentHeaders::SIGNATURE)) => 'not a payment'] : [];
    return ($gate ?? $this->container->get('x402_pay.gate'))->handle(
      Request::create('https://example.com/a', server: $server),
      Networks::requirements('eip155:5042002', '0.01', self::PAY_TO),
      ['url' => 'https://example.com/a'],
      NULL,
      function () {
        $this->delivered++;
        return new HttpResponse('paid');
      },
    );
  }

  /**
   * Asserts the answer of a paused sale.
   */
  private function assertPaused(HttpResponse $response, string $message): void {
    $this->assertSame(503, $response->getStatusCode(), $message);
    $this->assertSame(['error' => 'payments_paused'], json_decode((string) $response->getContent(), TRUE), $message);
    $this->assertSame('300', $response->headers->get('Retry-After'), $message);
  }

  /**
   * Paused: no price is quoted and no payment is read; resumed: it sells.
   */
  public function testPausedSiteSellsNothing(): void {
    $stop = $this->container->get('x402_pay.stop');
    $this->assertInstanceOf(Stop::class, $stop);
    $this->assertSame(402, $this->sale()->getStatusCode(), 'Running: the price.');

    $this->assertSame(['library' => NULL, 'module' => NULL], $stop->engage('testing'));
    $this->assertPaused($this->sale(), 'No offer to sign.');
    $this->assertPaused($this->sale(paying: TRUE), 'A payment is not even read.');
    $this->assertSame(0, $this->delivered);
    // A payment refused because the site is paused is not the buyer's fault:
    // it does not count against the visitor's limit of refused payments.
    $this->assertTrue($this->container->get('flood')->isAllowed('x402_pay.refused_payment', 1, 3600));

    $stop->release();
    $this->assertSame(402, $this->sale()->getStatusCode(), 'Resumed.');
    $this->assertSame(400, $this->sale(paying: TRUE)->getStatusCode(), 'And a payment is read again.');
  }

  /**
   * One switch alone pauses the sale, whichever it is.
   */
  public function testEitherSwitchAlonePausesTheSale(): void {
    $stop = $this->container->get('x402_pay.stop');

    // The flag file alone, as `touch` leaves it.
    mkdir(dirname($stop->file()), 0700, TRUE);
    touch($stop->file());
    $this->assertSame(['library' => TRUE, 'module' => FALSE], $stop->layers());
    $this->assertPaused($this->sale(), 'The flag file alone.');
    $stop->release();
    $this->assertSame(402, $this->sale()->getStatusCode());

    // The database alone, as another web head sees a pause set elsewhere.
    $this->container->get('state')->set('x402_pay.stopped', ['time' => 1, 'why' => '', 'uid' => 1]);
    $this->assertSame(['library' => FALSE, 'module' => TRUE], $stop->layers());
    $this->assertPaused($this->sale(), 'The database alone.');
    $stop->release();
    $this->assertSame(402, $this->sale()->getStatusCode());
  }

  /**
   * The library's sale asks its own switch: the second lock is real.
   *
   * With the module's check answering "running" (as it would if that code
   * were removed, or wrong), the flag file still stops the sale, inside the
   * library's middleware.
   */
  public function testTheLibraryStopsTheSaleEvenIfTheModulesCheckSaysRunning(): void {
    $real = $this->container->get('x402_pay.stop');
    $blind = new class($real) extends Stop {

      public function __construct(private readonly Stop $real) {}

      /**
       * {@inheritdoc}
       */
      public function engaged(): bool {
        return FALSE;
      }

      /**
       * {@inheritdoc}
       */
      public function library(): LibraryStop {
        return $this->real->library();
      }

    };
    $c = $this->container;
    $gate = new PaymentGate($c->get('x402_pay.facilitators'), $c->get('x402_pay.log'), $c->get('x402_pay.claims'), $c->get('x402_pay.psr17'), $c->get('x402_pay.psr_http_factory'), $c->get('x402_pay.http_foundation_factory'), stop: $blind);
    $this->assertSame(402, $this->sale($gate)->getStatusCode());

    $real->engage();
    $this->assertPaused($this->sale($gate), 'Answered by the library.');
    $this->assertSame(0, $this->delivered);
  }

  /**
   * The deprecated Buyer, built by hand, finds the switch and signs nothing.
   */
  #[IgnoreDeprecations]
  public function testPausedSiteBuysNothing(): void {
    // No answers queued: any request would fail the test with its own error.
    $mock = new MockHandler([]);
    $buyer = new Buyer(new Client(['handler' => HandlerStack::create($mock)]), new Signer(self::BUYER));
    $this->container->get('x402_pay.stop')->engage();
    try {
      $buyer->pay('https://example.com/a');
      $this->fail('A paused site paid.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('payments_paused', $e->getMessage());
    }
    $this->assertNull($mock->getLastRequest(), 'Nothing was asked, so nothing was signed.');
  }

  /**
   * The status report says the site is paused, and by which switch.
   */
  public function testTheStatusReportSaysPaused(): void {
    // Through the module handler, as the status report asks: core calls the
    // hook with no argument.
    $line = fn() => $this->container->get('module_handler')->invoke('x402_pay', 'runtime_requirements')['x402_pay_stop'];
    $this->assertSame('Running', (string) $line()['value']);
    $this->assertSame(RequirementSeverity::OK, $line()['severity']);

    $this->container->get('x402_pay.stop')->engage();
    $this->assertSame('PAUSED (library and module switch)', (string) $line()['value']);
    $this->assertSame(RequirementSeverity::Warning, $line()['severity']);
    $this->assertStringContainsString('drush x402:stop off', (string) $line()['description']);
  }

}
