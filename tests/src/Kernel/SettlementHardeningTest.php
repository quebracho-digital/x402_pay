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
   * USDC on Arc testnet.
   */
  private const TOKEN = '0x3600000000000000000000000000000000000000';

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
      'address' => self::TOKEN,
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
   * Only the network's token, from the payer, to payTo, counts as the payment.
   *
   * A facilitator that lies can cite any transaction that has a Transfer-shaped
   * event of the right amount to payTo; the check exists to catch that.
   */
  public function testSaleNeedsItsTokenItsBuyerAndItsOwnHash(): void {
    $transfer = fn(string $token, string $from) => [
      'address' => $token,
      'topics' => [
        '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
        '0x' . str_repeat('0', 24) . $from,
        '0x' . str_repeat('0', 24) . strtolower(substr(self::PAY_TO, 2)),
      ],
      'data' => '0x' . str_pad(dechex(10000), 64, '0', STR_PAD_LEFT),
    ];
    $payer = str_repeat('1', 40);
    $good = $this->row('settled', NULL, '0x' . str_repeat('a', 64));
    $fakeToken = $this->row('settled', NULL, '0x' . str_repeat('b', 64));
    $otherBuyer = $this->row('settled', NULL, '0x' . str_repeat('c', 64));
    $twice1 = $this->row('settled', NULL, '0x' . str_repeat('d', 64));
    $twice2 = $this->row('settled', NULL, '0x' . str_repeat('d', 64));

    $problems = array_column($this->reconciler([
      str_repeat('a', 64) => ['status' => '0x1', 'logs' => [$transfer(self::TOKEN, $payer)]],
      str_repeat('b', 64) => ['status' => '0x1', 'logs' => [$transfer('0x' . str_repeat('9', 40), $payer)]],
      str_repeat('c', 64) => ['status' => '0x1', 'logs' => [$transfer(self::TOKEN, str_repeat('2', 40))]],
      str_repeat('d', 64) => ['status' => '0x1', 'logs' => [$transfer(self::TOKEN, $payer)]],
    ])->settled(8), 'problem', 'id');

    $this->assertNull($problems[$good]);
    $this->assertSame('no transfer of the amount to payTo', $problems[$fakeToken], 'Another contract emitted it.');
    $this->assertSame('no transfer of the amount to payTo', $problems[$otherBuyer], 'Not from the payer.');
    $this->assertSame('transaction cited by another sale', $problems[$twice1]);
    $this->assertSame('transaction cited by another sale', $problems[$twice2]);
  }

  /**
   * A reverted or lost transaction is not "unpaid" while it can be spent.
   *
   * The buyer's signed authorization can be used by a transaction other than
   * the one logged (a rebroadcast with another gas price): the money moved
   * and the log must not say it did not.
   */
  public function testAnAuthorizationUsedElsewhereIsNotCalledUnpaid(): void {
    $nonce = '0x' . str_repeat('e', 64);
    $reverted = $this->row('failed', 'settlement_pending', '0x' . str_repeat('a', 64), 60, $nonce);
    $lost = $this->row('failed', 'settlement_pending', '0x' . str_repeat('b', 64), 2 * 86400, $nonce);
    $receipts = [str_repeat('a', 64) => ['status' => '0x0', 'logs' => []]];

    $this->reconciler($receipts, TRUE)->pending();
    $this->assertSame('authorization_used: paid by another transaction? check by hand', $this->outcome($reverted)[1]);
    $this->assertSame('authorization_used: paid by another transaction? check by hand', $this->outcome($lost)[1]);
    $this->assertSame('failed', $this->outcome($reverted)[0], 'Not claimed settled: a cancelled authorization is used too.');

    // Unused, they are what they were: reverted and dropped.
    $second = $this->row('failed', 'settlement_pending', '0x' . str_repeat('c', 64), 60, $nonce);
    $receipts[str_repeat('c', 64)] = ['status' => '0x0', 'logs' => []];
    $this->reconciler($receipts, FALSE)->pending();
    $this->assertSame('transaction_reverted', $this->outcome($second)[1]);
  }

  /**
   * The sale's own authorization must be spent in the transaction it cites.
   *
   * A facilitator can cite any transfer of the right amount to payTo; only the
   * token's AuthorizationUsed event names the signed authorization (payer and
   * nonce) that this sale was paid with.
   */
  public function testSaleNeedsItsAuthorizationSpentInItsTransaction(): void {
    $payer = '0x1111111111111111111111111111111111111111';
    $nonce = fn(string $c) => '0x' . str_repeat($c, 64);
    $transfer = [
      'address' => self::TOKEN,
      'topics' => [
        '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
        '0x' . str_repeat('0', 24) . substr($payer, 2),
        '0x' . str_repeat('0', 24) . strtolower(substr(self::PAY_TO, 2)),
      ],
      'data' => '0x' . str_pad(dechex(10000), 64, '0', STR_PAD_LEFT),
    ];
    $used = fn(string $n, string $by = '1') => [
      'address' => self::TOKEN,
      'topics' => [
        '0x98de503528ee59b575ef0c0a2576a82497bfc029a5685b209e9ec333479b10a5',
        '0x' . str_repeat('0', 24) . str_repeat($by, 40),
        $n,
      ],
      'data' => '0x',
    ];
    $good = $this->row('settled', NULL, '0x' . str_repeat('a', 64), 60, $nonce('1'));
    $bare = $this->row('settled', NULL, '0x' . str_repeat('b', 64), 60, $nonce('2'));
    $other = $this->row('settled', NULL, '0x' . str_repeat('c', 64), 60, $nonce('3'));
    $stranger = $this->row('settled', NULL, '0x' . str_repeat('d', 64), 60, $nonce('4'));
    $nobody = $this->row('settled', NULL, '0x' . str_repeat('e', 64), 60, $nonce('5'), NULL);

    $problems = array_column($this->reconciler([
      str_repeat('a', 64) => ['status' => '0x1', 'logs' => [$transfer, $used($nonce('1'))]],
      str_repeat('b', 64) => ['status' => '0x1', 'logs' => [$transfer]],
      str_repeat('c', 64) => ['status' => '0x1', 'logs' => [$transfer, $used($nonce('9'))]],
      str_repeat('d', 64) => ['status' => '0x1', 'logs' => [$transfer, $used($nonce('4'), '2')]],
      str_repeat('e', 64) => ['status' => '0x1', 'logs' => [$transfer, $used($nonce('5'))]],
    ])->settled(8), 'problem', 'id');

    $this->assertNull($problems[$good]);
    $this->assertSame('authorization not spent in this transaction', $problems[$bare], 'A transfer alone proves nothing.');
    $this->assertSame('authorization not spent in this transaction', $problems[$other], 'Another nonce.');
    $this->assertSame('authorization not spent in this transaction', $problems[$stranger], 'Another authorizer.');
    $this->assertSame('unverifiable: the log has no payer', $problems[$nobody]);
  }

  /**
   * The same transaction in another letter case is the same transaction.
   */
  public function testTheSameHashInAnotherCaseIsStillShared(): void {
    $one = $this->row('settled', NULL, '0x' . str_repeat('a', 64));
    $two = $this->row('settled', NULL, '0x' . str_repeat('A', 64));
    $transfer = [
      'address' => self::TOKEN,
      'topics' => [
        '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
        '0x' . str_repeat('0', 24) . str_repeat('1', 40),
        '0x' . str_repeat('0', 24) . strtolower(substr(self::PAY_TO, 2)),
      ],
      'data' => '0x' . str_pad(dechex(10000), 64, '0', STR_PAD_LEFT),
    ];
    $problems = array_column($this->reconciler([str_repeat('a', 64) => ['status' => '0x1', 'logs' => [$transfer]]])->settled(8), 'problem', 'id');
    $this->assertSame('transaction cited by another sale', $problems[$one]);
    $this->assertSame('transaction cited by another sale', $problems[$two]);
  }

  /**
   * A reverted row stays open while its authorization can still be spent.
   *
   * Anyone holding the signed payload can submit it first: the site's own
   * transaction then reverts, but the buyer did pay.
   */
  public function testRevertedRowIsReexaminedAndFlaggedWhenAuthorizationIsSpent(): void {
    $nonce = '0x' . str_repeat('e', 64);
    $reverted = $this->row('failed', 'transaction_reverted', '0x' . str_repeat('a', 64), 60, $nonce);
    $this->reconciler([str_repeat('a', 64) => ['status' => '0x0', 'logs' => []]], TRUE)->pending();
    $this->assertSame(Reconciler::USED, $this->outcome($reverted)[1]);
  }

  /**
   * A node that does not answer is not a verdict.
   */
  public function testFailedRpcCallLeavesRowPending(): void {
    $nonce = '0x' . str_repeat('e', 64);
    $row = $this->row('failed', 'settlement_pending', '0x' . str_repeat('a', 64), 60, $nonce);
    $receipts = [str_repeat('a', 64) => ['status' => '0x0', 'logs' => []]];

    $outcomes = array_column($this->reconciler($receipts, NULL)->pending(), 'outcome', 'id');
    $this->assertSame('no_answer', $outcomes[$row]);
    $this->assertSame('settlement_pending', $this->outcome($row)[1], 'Not called reverted on a guess.');

    $this->reconciler($receipts, TRUE)->pending();
    $this->assertSame(Reconciler::USED, $this->outcome($row)[1], 'The next run decides.');
  }

  /**
   * A reverted row is not judged while its authorization can still be spent.
   *
   * Until validBefore anyone holding the signed payload can submit it, so a
   * revert seen before then is not the end of the story.
   */
  public function testNothingIsJudgedBeforeValidBefore(): void {
    $now = \Drupal::time()->getRequestTime();
    $nonce = '0x' . str_repeat('e', 64);
    $early = $this->row('failed', 'settlement_pending', '0x' . str_repeat('a', 64), 60, $nonce, validBefore: $now + 3600);
    $late = $this->row('failed', 'settlement_pending', '0x' . str_repeat('b', 64), 60, $nonce, validBefore: $now - 10);
    $receipts = [
      str_repeat('a', 64) => ['status' => '0x0', 'logs' => []],
      str_repeat('b', 64) => ['status' => '0x0', 'logs' => []],
    ];

    $outcomes = array_column($this->reconciler($receipts)->pending(), 'outcome', 'id');
    $this->assertSame('pending', $outcomes[$early]);
    $this->assertSame('settlement_pending', $this->outcome($early)[1], 'Still open: the authorization can be spent.');
    $this->assertSame('reverted', $outcomes[$late]);
    $this->assertSame('transaction_reverted', $this->outcome($late)[1]);
  }

  /**
   * A node answering garbage must not take the cron run down with it.
   */
  public function testGarbageFromTheNodeIsNotFatal(): void {
    $nonce = '0x' . str_repeat('e', 64);
    $this->row('failed', 'settlement_pending', '0x' . str_repeat('a', 64), 60, $nonce);
    $this->row('settled', NULL, '0x' . str_repeat('b', 64), 60, $nonce);
    $garbage = [
      'address' => self::TOKEN,
      'topics' => [
        '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
        '0x1',
        '0x2',
      ],
      'data' => '0xzz',
    ];
    $receipts = [
      str_repeat('a', 64) => ['status' => '0x0', 'logs' => []],
      str_repeat('b', 64) => ['status' => '0x1', 'logs' => [$garbage]],
    ];
    $reconciler = $this->reconciler($receipts, '0xzz');
    $reconciler->pending();
    $problems = array_filter(array_column($reconciler->settled(8), 'problem'));
    $this->assertNotEmpty($problems, 'A row it cannot verify is a problem, not an exception.');
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
  private function reconciler(array $receipts, bool|string|null $authorizationUsed = FALSE): Reconciler {
    $answer = function ($request) use ($receipts, $authorizationUsed) {
      $call = json_decode((string) $request->getBody(), TRUE);
      if ($call['method'] === 'eth_call') {
        if ($authorizationUsed === NULL) {
          $error = ['code' => -32005, 'message' => 'rate limited'];
          return new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'error' => $error]));
        }
        // authorizationState(payer, nonce): a bool as a 32-byte word.
        $word = is_string($authorizationUsed) ? $authorizationUsed : '0x' . str_repeat('0', 63) . ($authorizationUsed ? '1' : '0');
        return new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $word]));
      }
      $receipt = $receipts[strtolower(substr($call['params'][0], 2))] ?? NULL;
      return new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $receipt]));
    };
    $client = new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 10, $answer)))]);
    $psr17 = new HttpFactory();
    return new Reconciler(\Drupal::database(), $client, $psr17, $this->container->get('datetime.time'), $this->container->get('state'), new NullLogger());
  }

  /**
   * Logs a sale on Arc testnet $age seconds ago; returns its id.
   */
  private function row(string $status, ?string $error, string $transaction, int $age = 60, ?string $nonce = NULL, ?string $payer = '0x1111111111111111111111111111111111111111', ?int $validBefore = NULL): int {
    return (int) \Drupal::database()->insert('x402_pay_log')->fields([
      'created' => \Drupal::time()->getRequestTime() - $age,
      'resource' => 'https://example.com/a',
      'network' => 'eip155:5042002',
      'amount' => '10000',
      'pay_to' => self::PAY_TO,
      'payer' => $payer,
      'status' => $status,
      'transaction' => $transaction,
      'handler_status' => 200,
      'error' => $error,
      'nonce' => $nonce,
      'valid_before' => $validBefore,
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
