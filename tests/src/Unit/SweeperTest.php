<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\Sweeper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Sweeping seller wallets: what moves is the balance less the most gas.
 */
#[Group('x402_pay')]
final class SweeperTest extends UnitTestCase {

  private const COLD = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';

  /**
   * The JSON-RPC requests sent.
   */
  private array $history = [];

  /**
   * A sweeper of Anvil's first key, answering RPC calls by method.
   *
   * Every network gets the same answers.
   */
  private function sweeper(array $results, string $min = '0.01'): Sweeper {
    $handler = function ($request) use ($results) {
      $method = json_decode((string) $request->getBody(), TRUE)['method'];
      $result = $results[$method] ?? NULL;
      return new Response(200, [], json_encode($result instanceof \Throwable ? ['error' => ['message' => $result->getMessage()]] : ['result' => $result]));
    };
    $stack = HandlerStack::create(new MockHandler(array_fill(0, 20, $handler)));
    $stack->push(Middleware::history($this->history));
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80');
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->method('getKey')->willReturn($key);
    $sweep = ['to' => self::COLD, 'keys' => ['seller'], 'min' => $min];
    $config = $this->getConfigFactoryStub(['x402_pay.settings' => ['sweep' => $sweep]]);
    return new Sweeper(new Client(['handler' => $stack]), $config, $keys, $this->createMock(StateInterface::class), $this->createMock(TimeInterface::class), new NullLogger());
  }

  /**
   * The RPC answers for a wallet holding 0.14 USDC at 25 gwei.
   */
  private function wallet(): array {
    return [
      'eth_call' => '0x' . str_pad(dechex(140000), 64, '0', STR_PAD_LEFT),
      'eth_gasPrice' => '0x' . dechex(25_000_000_000),
      'eth_estimateGas' => '0x' . dechex(50000),
      'eth_getTransactionCount' => '0x3',
      'eth_sendRawTransaction' => '0xtx',
    ];
  }

  /**
   * Balance less 60,000 gas at 50 gwei (0.003 USDC) moves to the cold wallet.
   */
  public function testMovesTheBalanceLessTheMostGas(): void {
    $rows = $this->sweeper($this->wallet())->sweep();

    $this->assertSame('137000', $rows[0]['amount']);
    $this->assertSame('0xtx', $rows[0]['transaction']);
    $sent = array_filter($this->history, fn($h) => str_contains((string) $h['request']->getBody(), 'eth_sendRawTransaction'));
    $this->assertNotEmpty($sent);
  }

  /**
   * A dry run says what would move and sends nothing.
   */
  public function testDryRunSendsNothing(): void {
    $rows = $this->sweeper($this->wallet())->sweep(FALSE);

    $this->assertSame('137000', $rows[0]['amount']);
    $this->assertNull($rows[0]['transaction']);
    foreach ($this->history as $h) {
      $this->assertStringNotContainsString('eth_sendRawTransaction', (string) $h['request']->getBody());
    }
  }

  /**
   * Below the minimum a wallet waits.
   */
  public function testBelowTheMinimumWaits(): void {
    $rows = $this->sweeper($this->wallet(), '0.5')->sweep();

    $this->assertSame('140000', $rows[0]['balance']);
    $this->assertSame('0', $rows[0]['amount']);
    $this->assertNull($rows[0]['transaction']);
  }

  /**
   * A node that fails is reported, not hidden behind an empty balance.
   */
  public function testRpcErrorsAreReported(): void {
    $rows = $this->sweeper(['eth_call' => new \RuntimeException('rate limited')])->sweep();

    $this->assertStringContainsString('rate limited', $rows[0]['error']);
  }

}
