<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Core\Lock\NullLockBackend;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\ChainFacilitator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Our own facilitator: the token judges the signature, we judge the price.
 */
#[Group('x402_pay')]
final class ChainFacilitatorTest extends UnitTestCase {

  private const BUYER = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';
  private const PAY_TO = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';

  /**
   * The JSON-RPC requests sent.
   */
  private array $history = [];

  /**
   * A facilitator on Arc testnet whose node answers by method.
   */
  private function facilitator(array $results): ChainFacilitator {
    $handler = function ($request) use ($results) {
      $method = json_decode((string) $request->getBody(), TRUE)['method'];
      $result = $results[$method] ?? NULL;
      return new Response(200, [], json_encode($result instanceof \Throwable ? ['error' => ['message' => $result->getMessage()]] : ['result' => $result]));
    };
    $stack = HandlerStack::create(new MockHandler(array_fill(0, 20, $handler)));
    $stack->push(Middleware::history($this->history));
    $key = $this->createMock(KeyInterface::class);
    // Anvil's third key: the gas wallet.
    $key->method('getKeyValue')->willReturn('0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a');
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->method('getKey')->willReturn($key);
    $facilitator = ['own_networks' => ['eip155:5042002'], 'own_key_id' => 'gas'];
    $config = $this->getConfigFactoryStub(['x402_pay.settings' => ['facilitator' => $facilitator]]);
    return new ChainFacilitator(new Client(['handler' => $stack]), $config, $keys, new NullLockBackend(), 0, 3);
  }

  /**
   * A buyer's payment of 0.01 USDC to PAY_TO.
   */
  private function payload(array $authorization = []): array {
    return [
      'x402Version' => 2,
      'payload' => [
        'signature' => '0x' . str_repeat('11', 32) . str_repeat('22', 32) . '1c',
        'authorization' => $authorization + [
          'from' => self::BUYER,
          'to' => self::PAY_TO,
          'value' => '10000',
          'validAfter' => '0',
          'validBefore' => '9999999999',
          'nonce' => '0x' . str_repeat('33', 32),
        ],
      ],
    ];
  }

  /**
   * What we ask for.
   */
  private function requirements(): array {
    return [
      'scheme' => 'exact',
      'network' => 'eip155:5042002',
      'amount' => '10000',
      'asset' => '0x3600000000000000000000000000000000000000',
      'payTo' => self::PAY_TO,
    ];
  }

  /**
   * The methods called, in order.
   */
  private function methods(): array {
    return array_map(fn($h) => json_decode((string) $h['request']->getBody(), TRUE)['method'], $this->history);
  }

  /**
   * The calldata equals viem's encodeFunctionData.
   */
  public function testCalldataEqualsViem(): void {
    $this->assertSame(
      '0xe3ee160e000000000000000000000000f39fd6e51aad88f6f4ce6ab8827279cfffb9226600000000000000000000000070997970c51812dc3a010c7d01b50e0d17dc79c80000000000000000000000000000000000000000000000000000000000002710000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000002540be3ff3333333333333333333333333333333333333333333333333333333333333333000000000000000000000000000000000000000000000000000000000000001c11111111111111111111111111111111111111111111111111111111111111112222222222222222222222222222222222222222222222222222222222222222',
      ChainFacilitator::calldata($this->payload()),
    );
  }

  /**
   * Only the configured networks are ours.
   */
  public function testHandlesOnlyItsNetworks(): void {
    $facilitator = $this->facilitator([]);
    $this->assertTrue($facilitator->handles('eip155:5042002'));
    $this->assertFalse($facilitator->handles('eip155:5042'));
  }

  /**
   * Verify simulates the transfer from the gas wallet.
   */
  public function testVerifySimulatesTheTransfer(): void {
    $result = $this->facilitator(['eth_call' => '0x'])->verify($this->payload(), $this->requirements());

    $this->assertTrue($result['isValid']);
    $this->assertSame(self::BUYER, $result['payer']);
    $call = json_decode((string) $this->history[0]['request']->getBody(), TRUE)['params'][0];
    $this->assertSame('0x3600000000000000000000000000000000000000', $call['to']);
    $this->assertSame('0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC', $call['from']);
    $this->assertSame(ChainFacilitator::calldata($this->payload()), $call['data']);
  }

  /**
   * A revert (bad signature, no balance, used nonce) is an invalid payment.
   */
  public function testRevertIsInvalid(): void {
    $result = $this->facilitator(['eth_call' => new \RuntimeException('execution reverted: FiatTokenV2: invalid signature')])
      ->verify($this->payload(), $this->requirements());

    $this->assertFalse($result['isValid']);
    $this->assertStringContainsString('invalid signature', $result['invalidReason']);
  }

  /**
   * What the token cannot know is refused without asking the chain.
   *
   * Another seller, another amount, an expired or a malformed payment.
   */
  public function testWhatTheTokenCannotKnowIsCheckedHere(): void {
    $cases = [
      'recipient_mismatch' => ['to' => self::BUYER],
      'authorization_value' => ['value' => '1'],
      'valid_before' => ['validBefore' => (string) (time() + 1)],
      'valid_after' => ['validAfter' => (string) (time() + 600)],
      'no nonce' => ['nonce' => NULL],
    ];
    foreach ($cases as $reason => $authorization) {
      $payload = $this->payload($authorization);
      $payload['payload']['authorization'] = array_filter($payload['payload']['authorization'], fn($v) => $v !== NULL);
      $result = $this->facilitator([])->verify($payload, $this->requirements());
      $this->assertFalse($result['isValid'], $reason);
      $this->assertStringContainsString($reason, $result['invalidReason']);
    }
    $this->assertSame([], $this->history);
  }

  /**
   * Settle sends the signed transaction and waits for its receipt.
   */
  public function testSettleSendsAndWaitsForTheReceipt(): void {
    $result = $this->facilitator([
      'eth_gasPrice' => '0x' . dechex(25_000_000_000),
      'eth_estimateGas' => '0x' . dechex(60000),
      'eth_getTransactionCount' => '0x7',
      'eth_sendRawTransaction' => '0xtx',
      'eth_getTransactionReceipt' => ['status' => '0x1'],
    ])->settle($this->payload(), $this->requirements());

    $this->assertTrue($result['success']);
    $this->assertSame('0xtx', $result['transaction']);
    $this->assertSame(self::BUYER, $result['payer']);
    $this->assertSame([
      'eth_gasPrice',
      'eth_estimateGas',
      'eth_getTransactionCount',
      'eth_sendRawTransaction',
      'eth_getTransactionReceipt',
    ], $this->methods());
  }

  /**
   * A reverted transaction is not a sale.
   */
  public function testRevertedTransactionIsNotSale(): void {
    $result = $this->facilitator([
      'eth_gasPrice' => '0x1',
      'eth_estimateGas' => '0x1',
      'eth_getTransactionCount' => '0x0',
      'eth_sendRawTransaction' => '0xtx',
      'eth_getTransactionReceipt' => ['status' => '0x0'],
    ])->settle($this->payload(), $this->requirements());

    $this->assertFalse($result['success']);
    $this->assertSame('transaction_reverted', $result['errorReason']);
  }

  /**
   * A payment that would revert costs no gas: nothing is sent.
   */
  public function testBadPaymentSendsNothing(): void {
    $result = $this->facilitator([
      'eth_gasPrice' => '0x1',
      'eth_estimateGas' => new \RuntimeException('execution reverted: FiatTokenV2: authorization is used or canceled'),
    ])->settle($this->payload(), $this->requirements());

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('authorization is used', $result['errorReason']);
    $this->assertNotContains('eth_sendRawTransaction', $this->methods());
  }

  /**
   * Not mined in time: not a success, and the transaction is reported.
   */
  public function testNotMinedInTimeIsPending(): void {
    $result = $this->facilitator([
      'eth_gasPrice' => '0x1',
      'eth_estimateGas' => '0x1',
      'eth_getTransactionCount' => '0x0',
      'eth_sendRawTransaction' => '0xtx',
      'eth_getTransactionReceipt' => NULL,
    ])->settle($this->payload(), $this->requirements());

    $this->assertFalse($result['success']);
    $this->assertSame('settlement_pending', $result['errorReason']);
    $this->assertSame('0xtx', $result['transaction']);
  }

  /**
   * A send the node refuses is retried, then found by its hash if it got in.
   */
  public function testARefusedSendIsRetriedAndFoundByItsHash(): void {
    $result = $this->facilitator([
      'eth_gasPrice' => '0x1',
      'eth_estimateGas' => '0x1',
      'eth_getTransactionCount' => '0x0',
      'eth_sendRawTransaction' => new \RuntimeException('internal error'),
      'eth_getTransactionReceipt' => ['status' => '0x1'],
    ])->settle($this->payload(), $this->requirements());

    $this->assertTrue($result['success']);
    $this->assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $result['transaction']);
    $this->assertSame(2, count(array_keys($this->methods(), 'eth_sendRawTransaction')));
  }

  /**
   * Refused twice and not on chain: not a sale, with the node's reason.
   */
  public function testARefusedSendNotOnChainIsNotSale(): void {
    $result = $this->facilitator([
      'eth_gasPrice' => '0x1',
      'eth_estimateGas' => '0x1',
      'eth_getTransactionCount' => '0x0',
      'eth_sendRawTransaction' => new \RuntimeException('internal error'),
      'eth_getTransactionReceipt' => NULL,
    ])->settle($this->payload(), $this->requirements());

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('internal error', $result['errorReason']);
  }

}
