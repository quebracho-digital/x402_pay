<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Drupal\x402_pay\CircleFacilitator;
use Drupal\x402_pay\Eip712;
use Drupal\x402_pay\FacilitatorException;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\x402_pay\Signer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Circle's Facilitator Service client: keyless (seller proof) and API key.
 */
#[Group('x402_pay')]
final class CircleFacilitatorTest extends UnitTestCase {

  private const BASE = 'https://api.circle.com/v1/facilitator/x402';

  /**
   * The requests sent, as Guzzle history.
   */
  private array $history = [];
  /**
   * The seller's key.
   */
  private Signer $seller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->seller = new Signer('0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80');
  }

  /**
   * A facilitator that answers with these responses, in order.
   */
  private function facilitator(Response ...$responses): CircleFacilitator {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    return new CircleFacilitator(new Client(['handler' => $stack]), self::BASE, pollDelaySeconds: 0);
  }

  /**
   * The same, on a site set to use this Circle API key ('' for a missing one).
   */
  private function withApiKey(string $apiKey, Response ...$responses): CircleFacilitator {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    $facilitator = ['auth' => 'api_key', 'api_key_id' => 'circle'];
    $config = $this->getConfigFactoryStub(['x402_pay.settings' => ['facilitator' => $facilitator]]);
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn($apiKey);
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->method('getKey')->with('circle')->willReturn($key);
    return new CircleFacilitator(new Client(['handler' => $stack]), self::BASE, pollDelaySeconds: 0, config: $config, keys: $keys);
  }

  /**
   * With an API key: a bearer token, no proof, and payTo needs no key here.
   */
  public function testApiKeyReplacesTheProofAndNeedsNoSellerKey(): void {
    $result = $this->withApiKey('TEST_API_KEY:abc:def', new Response(200, [], '{"isValid":true,"payer":"0xbuyer"}'))
      ->verify($this->payload(), $this->requirements(), NULL);

    $this->assertTrue($result['isValid']);
    $request = $this->history[0]['request'];
    $this->assertSame('Bearer TEST_API_KEY:abc:def', $request->getHeaderLine('Authorization'));
    $this->assertFalse($request->hasHeader('Facilitator-Seller-Proof'));
  }

  /**
   * API key mode with the key missing fails before calling Circle.
   */
  public function testApiKeyModeWithoutTheKeyFails(): void {
    $this->expectException(FacilitatorException::class);
    $this->expectExceptionMessage('No Circle API key');
    try {
      $this->withApiKey('', new Response(200))->settle($this->payload(), $this->requirements(), $this->seller);
    }
    finally {
      $this->assertSame([], $this->history);
    }
  }

  /**
   * Keyless without the payTo key fails before calling Circle.
   */
  public function testKeylessWithoutTheSellerKeyFails(): void {
    $this->expectException(FacilitatorException::class);
    $this->expectExceptionMessage('needs the payTo key');
    $this->facilitator(new Response(200))->verify($this->payload(), $this->requirements(), NULL);
  }

  /**
   * The payment requirements of the test product.
   */
  private function requirements(): array {
    return [
      'scheme' => 'exact',
      'network' => 'eip155:5042002',
      'amount' => '10000',
      'asset' => '0x3600000000000000000000000000000000000000',
      'payTo' => $this->seller->address(),
      'maxTimeoutSeconds' => 60,
      'extra' => ['name' => 'USDC', 'version' => '2'],
    ];
  }

  /**
   * A signed payment payload.
   */
  private function payload(): array {
    return [
      'x402Version' => 2,
      'accepted' => $this->requirements(),
      'payload' => ['signature' => '0x00', 'authorization' => []],
    ];
  }

  /**
   * The seller proof sent with the i-th request, decoded.
   */
  private function proof(int $i): array {
    return json_decode(base64_decode(strtr($this->history[$i]['request']->getHeaderLine('Facilitator-Seller-Proof'), '-_', '+/')), TRUE);
  }

  /**
   * Verify sends both objects with verify proof.
   */
  public function testVerifySendsBothObjectsWithVerifyProof(): void {
    $result = $this->facilitator(new Response(200, [], '{"isValid":true,"payer":"0xbuyer"}'))
      ->verify($this->payload(), $this->requirements(), $this->seller);

    $this->assertTrue($result['isValid']);
    $request = $this->history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame(self::BASE . '/verify', (string) $request->getUri());
    $body = (string) $request->getBody();
    $this->assertSame([
      'x402Version' => 2,
      'paymentPayload' => $this->payload(),
      'paymentRequirements' => $this->requirements(),
    ], json_decode($body, TRUE));
    $proof = $this->proof(0);
    $this->assertSame($this->seller->address(), $proof['payTo']);
    $this->assertSame('eip155:5042002', $proof['network']);
    $this->assertSame(300, $proof['expiresAt'] - $proof['issuedAt']);
  }

  /**
   * The proof signs the exact body sent.
   */
  public function testTheProofSignsTheExactBodySent(): void {
    $this->facilitator(new Response(200, [], '{"success":true,"transaction":"0xtx","network":"eip155:5042002","payer":"0xbuyer"}'))
      ->settle($this->payload(), $this->requirements(), $this->seller);

    $request = $this->history[0]['request'];
    $proof = $this->proof(0);
    // Rebuild what the seller signed and check the signature recovers to it.
    $digest = Eip712::hash(
      ['name' => 'Circle Facilitator Seller Request', 'version' => '1', 'chainId' => 5042002],
      [
        'SellerRequest' => [
        ['name' => 'purpose', 'type' => 'string'], ['name' => 'method', 'type' => 'string'], [
          'name' => 'bodyHash',
          'type' => 'bytes32',
        ],
        ['name' => 'network', 'type' => 'string'], ['name' => 'payTo', 'type' => 'address'], [
          'name' => 'nonce',
          'type' => 'bytes32',
        ],
        ['name' => 'issuedAt', 'type' => 'uint64'], ['name' => 'expiresAt', 'type' => 'uint64'],
        ],
      ],
      'SellerRequest',
      [
        'purpose' => 'settle',
        'method' => 'POST',
        'bodyHash' => Eip712::keccak((string) $request->getBody()),
        'network' => 'eip155:5042002',
        'payTo' => $this->seller->address(),
        'nonce' => $proof['nonce'],
        'issuedAt' => $proof['issuedAt'],
        'expiresAt' => $proof['expiresAt'],
      ],
    );
    $this->assertSame($this->seller->sign($digest), $proof['signature']);
  }

  /**
   * Pending settlement is polled until completed.
   */
  public function testPendingSettlementIsPolledUntilCompleted(): void {
    $result = $this->facilitator(
      new Response(200, [], '{"success":false,"errorReason":"settlement_pending","transaction":"","network":"eip155:5042002","payer":"0xbuyer","extensions":{"settlement-status":{"status":"pending","paymentId":"pay-1"}}}'),
      new Response(200, [], '{"paymentId":"pay-1","status":"pending","transaction":null}'),
      new Response(200, [], '{"paymentId":"pay-1","status":"completed","transaction":"0xtx","network":"eip155:5042002","amount":"10000","payer":"0xbuyer"}'),
    )->settle($this->payload(), $this->requirements(), $this->seller);

    $this->assertTrue($result['success']);
    $this->assertSame('0xtx', $result['transaction']);
    $this->assertSame(self::BASE . '/status/pay-1', (string) $this->history[1]['request']->getUri());
    $this->assertSame('GET', $this->history[1]['request']->getMethod());
  }

  /**
   * Still pending after polling fails.
   */
  public function testStillPendingAfterPollingFails(): void {
    $pending = '{"paymentId":"pay-1","status":"pending","transaction":null}';
    $result = $this->facilitator(
      new Response(200, [], '{"success":false,"errorReason":"settlement_pending","transaction":"","extensions":{"settlement-status":{"status":"pending","paymentId":"pay-1"}}}'),
      ...array_fill(0, 10, new Response(200, [], $pending)),
    )->settle($this->payload(), $this->requirements(), $this->seller);

    $this->assertFalse($result['success']);
    $this->assertSame('settlement_pending', $result['errorReason']);
  }

  /**
   * Http errors become facilitator exceptions with circle reason.
   */
  public function testHttpErrorsBecomeFacilitatorExceptionsWithCircleReason(): void {
    $this->expectException(FacilitatorException::class);
    $this->expectExceptionMessage('registration_required');
    $this->facilitator(new Response(403, [], '{"code":403,"message":"Registration required to continue settling on this address","errors":[{"reason":"registration_required"}]}'))
      ->settle($this->payload(), $this->requirements(), $this->seller);
  }

}
