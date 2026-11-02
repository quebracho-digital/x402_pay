<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\x402_pay\Reconciler;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
use Quebracho\X402\ChainFacilitator;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentHeaders;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * What bounds a sale settled on chain: gas cap, refusals, reconciliation.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class SettlementHardeningTest extends KernelTestBase {

  private const PAY_TO = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['x402_pay', 'key'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('x402_pay', ['x402_pay_log', 'x402_pay_claim']);
    $this->installConfig(['x402_pay']);
  }

  /**
   * Our own facilitator gets the configured gas cap, in wei.
   */
  public function testTheGasCapReachesTheFacilitator(): void {
    $this->config('x402_pay.settings')->set('facilitator.own_networks', ['eip155:5042002'])->set('facilitator.own_key_id', 'gas')->save();
    $key = \Drupal::entityTypeManager()->getStorage('key')->create([
      'id' => 'gas',
      'label' => 'Gas',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80'],
    ]);
    $key->save();

    $this->assertSame('50000000000000000', $this->gasCap(), 'The default, 0.05.');
    $this->config('x402_pay.settings')->set('facilitator.max_gas_cost', '')->save();
    $this->assertNull($this->gasCap(), 'Empty: no cap.');
  }

  /**
   * A visitor whose payments keep being refused is turned away for a while.
   */
  public function testRefusedPaymentsAreLimitedPerVisitor(): void {
    $gate = $this->container->get('x402_pay.gate');
    $req = Networks::requirements('eip155:5042002', '0.01', self::PAY_TO);
    $refused = fn() => $gate->handle(
      Request::create('https://example.com/a', server: ['HTTP_' . str_replace('-', '_', strtoupper(PaymentHeaders::SIGNATURE)) => 'not a payment']),
      $req,
      ['url' => 'https://example.com/a'],
      NULL,
      fn() => new HttpResponse('paid'),
    )->getStatusCode();

    for ($i = 0; $i < 30; $i++) {
      $this->assertSame(400, $refused(), 'Not a payment.');
    }
    $this->assertSame(429, $refused());
    $unpaid = $gate->handle(Request::create('https://example.com/a'), $req, ['url' => 'https://example.com/a'], NULL, fn() => new HttpResponse('paid'));
    $this->assertSame(402, $unpaid->getStatusCode(), 'Asking the price is never limited.');
  }

  /**
   * The limit is a setting: lower, higher or off.
   */
  public function testTheRefusalLimitComesFromConfig(): void {
    $gate = $this->container->get('x402_pay.gate');
    $req = Networks::requirements('eip155:5042002', '0.01', self::PAY_TO);
    $refused = fn() => $gate->handle(
      Request::create('https://example.com/a', server: ['HTTP_' . str_replace('-', '_', strtoupper(PaymentHeaders::SIGNATURE)) => 'not a payment']),
      $req,
      ['url' => 'https://example.com/a'],
      NULL,
      fn() => new HttpResponse('paid'),
    )->getStatusCode();

    $this->assertSame(30, $this->config('x402_pay.settings')->get('refused_payments'), 'The default.');
    $this->config('x402_pay.settings')->set('refused_payments', 2)->save();
    $this->assertSame([400, 400, 429], [$refused(), $refused(), $refused()]);

    $this->config('x402_pay.settings')->set('refused_payments', 0)->save();
    $this->assertSame(400, $refused(), 'Zero is no limit.');
  }

  /**
   * A pending settlement takes the outcome its receipt shows.
   */
  public function testPendingSettlementsFollowTheirReceipt(): void {
    $mined = $this->row('failed', 'settlement_pending', '0x' . str_repeat('a', 64));
    $reverted = $this->row('failed', 'settlement_pending', '0x' . str_repeat('b', 64));
    $waiting = $this->row('failed', 'settlement_pending', '0x' . str_repeat('c', 64));
    $lost = $this->row('failed', 'settlement_pending', '0x' . str_repeat('d', 64), 2 * 86400);

    $outcomes = array_column($this->reconciler([
      str_repeat('a', 64) => ['status' => '0x1', 'logs' => []],
      str_repeat('b', 64) => ['status' => '0x0', 'logs' => []],
    ])->pending(), 'outcome', 'id');

    $this->assertSame([$mined => 'settled', $reverted => 'reverted', $waiting => 'pending', $lost => 'dropped'], $outcomes);
    $this->assertSame(['settled', Reconciler::REFUND], $this->outcome($mined));
    $this->assertSame(['failed', 'transaction_reverted'], $this->outcome($reverted));
    $this->assertSame(['failed', 'settlement_pending'], $this->outcome($waiting));
    $this->assertSame(['failed', 'settlement dropped: never mined'], $this->outcome($lost));
  }

  /**
   * A settled sale must show its transfer to payTo, of its amount, on chain.
   */
  public function testSettledSalesAreCheckedAgainstTheChain(): void {
    $transfer = fn(string $amount) => [
      'topics' => [
        '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
        '0x' . str_repeat('0', 24) . str_repeat('1', 40),
        '0x' . str_repeat('0', 24) . strtolower(substr(self::PAY_TO, 2)),
      ],
      'data' => '0x' . str_pad(dechex((int) $amount), 64, '0', STR_PAD_LEFT),
    ];
    $good = $this->row('settled', NULL, '0x' . str_repeat('a', 64));
    $short = $this->row('settled', NULL, '0x' . str_repeat('b', 64));
    $missing = $this->row('settled', NULL, '0x' . str_repeat('c', 64));
    $old = $this->row('settled', NULL, '0x' . str_repeat('d', 64), 30 * 86400);

    $problems = array_column($this->reconciler([
      str_repeat('a', 64) => ['status' => '0x1', 'logs' => [$transfer('10000')]],
      str_repeat('b', 64) => ['status' => '0x1', 'logs' => [$transfer('9999')]],
    ])->settled(8), 'problem', 'id');

    $this->assertSame([$good => NULL, $short => 'no transfer of the amount to payTo', $missing => 'no receipt on chain'], $problems);
    $this->assertArrayNotHasKey($old, $problems, 'Older than the window.');
  }

  /**
   * The wei cap the gate's facilitator for Arc testnet got.
   */
  private function gasCap(): ?string {
    $facilitator = $this->container->get('x402_pay.facilitators')->for('eip155:5042002', NULL);
    $this->assertInstanceOf(ChainFacilitator::class, $facilitator);
    return (new \ReflectionProperty($facilitator, 'maxGasCost'))->getValue($facilitator);
  }

  /**
   * A reconciler whose node knows these receipts, by hash without 0x.
   */
  private function reconciler(array $receipts): Reconciler {
    $answer = function ($request) use ($receipts) {
      $hash = json_decode((string) $request->getBody(), TRUE)['params'][0];
      $receipt = $receipts[substr($hash, 2)] ?? NULL;
      return new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $receipt]));
    };
    $client = new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 10, $answer)))]);
    $psr17 = new HttpFactory();
    return new Reconciler(\Drupal::database(), $client, $psr17, $this->container->get('datetime.time'), $this->container->get('state'), new NullLogger());
  }

  /**
   * Logs a sale on Arc testnet $age seconds ago; returns its id.
   */
  private function row(string $status, ?string $error, string $transaction, int $age = 60): int {
    return (int) \Drupal::database()->insert('x402_pay_log')->fields([
      'created' => \Drupal::time()->getRequestTime() - $age,
      'resource' => 'https://example.com/a',
      'network' => 'eip155:5042002',
      'amount' => '10000',
      'pay_to' => self::PAY_TO,
      'payer' => '0x' . str_repeat('1', 40),
      'status' => $status,
      'transaction' => $transaction,
      'handler_status' => 200,
      'error' => $error,
    ])->execute();
  }

  /**
   * A row's status and error.
   */
  private function outcome(int $id): array {
    $row = \Drupal::database()->select('x402_pay_log', 'l')->fields('l', ['status', 'error'])->condition('id', $id)->execute()->fetchAssoc();
    return [$row['status'], $row['error']];
  }

}
