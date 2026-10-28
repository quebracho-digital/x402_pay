<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\Buyer;
use Drupal\x402_pay\Eip712;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Signer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * The buyer pays exactly what the 402 asks, and nothing it refuses.
 */
#[Group('x402_pay')]
final class BuyerTest extends UnitTestCase {

  /**
   * Anvil's first dev key: public, never holds funds.
   */
  private const KEY = '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80';

  /**
   * What the 402 asks.
   */
  private const REQUIREMENTS = [
    'scheme' => 'exact',
    'network' => 'eip155:5042002',
    'amount' => '10000',
    'asset' => '0x3600000000000000000000000000000000000000',
    'payTo' => '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
    'maxTimeoutSeconds' => 60,
    'extra' => ['name' => 'USDC', 'version' => '2'],
  ];

  /**
   * The requests sent.
   */
  private array $history = [];

  /**
   * A buyer whose HTTP client answers $responses in order.
   */
  private function buyer(Response ...$responses): Buyer {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    return new Buyer(new Client(['handler' => $stack]), new Signer(self::KEY));
  }

  /**
   * A 402 asking for REQUIREMENTS.
   */
  private function required(): Response {
    $required = [
      'x402Version' => 2,
      'resource' => ['url' => 'https://example.com/x'],
      'accepts' => [self::REQUIREMENTS],
    ];
    return new Response(402, [PaymentHeaders::REQUIRED => PaymentHeaders::encode($required)]);
  }

  /**
   * The payment carries what the 402 asked, signed by the buyer.
   */
  public function testItSignsExactlyWhatIsAsked(): void {
    $settled = PaymentHeaders::encode(['success' => TRUE, 'transaction' => '0xabc', 'network' => 'eip155:5042002']);
    $paid = $this->buyer($this->required(), new Response(200, [PaymentHeaders::RESPONSE => $settled], '{"ok":true}'))
      ->pay('https://example.com/x', ['q' => 1]);

    $this->assertSame(200, $paid['status']);
    $this->assertSame('0xabc', $paid['tx']);
    $this->assertCount(2, $this->history);
    $this->assertSame('POST', $this->history[1]['request']->getMethod());
    $this->assertSame('{"q":1}', (string) $this->history[1]['request']->getBody());
    $payment = PaymentHeaders::decode($this->history[1]['request']->getHeaderLine(PaymentHeaders::SIGNATURE));
    $auth = $payment['payload']['authorization'];
    $this->assertSame((new Signer(self::KEY))->address(), $auth['from']);
    $this->assertSame(self::REQUIREMENTS['payTo'], $auth['to']);
    $this->assertSame('10000', $auth['value']);
    $this->assertSame('eip3009', $payment['accepted']['extra']['assetTransferMethod']);
    // The signature is the buyer's, over that authorization.
    $digest = Eip712::hash(
      ['name' => 'USDC', 'version' => '2', 'chainId' => 5042002, 'verifyingContract' => self::REQUIREMENTS['asset']],
      [
        'TransferWithAuthorization' => [
          ['name' => 'from', 'type' => 'address'],
          ['name' => 'to', 'type' => 'address'],
          ['name' => 'value', 'type' => 'uint256'],
          ['name' => 'validAfter', 'type' => 'uint256'],
          ['name' => 'validBefore', 'type' => 'uint256'],
          ['name' => 'nonce', 'type' => 'bytes32'],
        ],
      ],
      'TransferWithAuthorization',
      $auth,
    );
    $this->assertSame((new Signer(self::KEY))->sign($digest), $payment['payload']['signature']);
  }

  /**
   * A price the callback refuses sends no payment.
   */
  public function testRefusedPriceSendsNoPayment(): void {
    try {
      $this->buyer($this->required())->pay('https://example.com/x', NULL, fn() => throw new \RuntimeException('Over budget'));
      $this->fail('Expected a refusal.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Over budget', $e->getMessage());
    }
    $this->assertCount(1, $this->history);
    $this->assertSame('GET', $this->history[0]['request']->getMethod());
  }

}
