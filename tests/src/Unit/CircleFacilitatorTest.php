<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\CircleFacilitator;
use Drupal\x402_pay\Eip712;
use Drupal\x402_pay\FacilitatorException;
use Drupal\x402_pay\Signer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Circle's Facilitator Service client, keyless (seller proof) mode.
 *
 * @group x402_pay
 */
final class CircleFacilitatorTest extends UnitTestCase {

  private const BASE = 'https://api.circle.com/v1/facilitator/x402';

  private array $history = [];
  private Signer $seller;

  protected function setUp(): void {
    parent::setUp();
    $this->seller = new Signer('0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80');
  }

  private function facilitator(Response ...$responses): CircleFacilitator {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    return new CircleFacilitator(new Client(['handler' => $stack]), self::BASE, pollDelaySeconds: 0);
  }

  private function requirements(): array {
    return ['scheme' => 'exact', 'network' => 'eip155:5042002', 'amount' => '10000', 'asset' => '0x3600000000000000000000000000000000000000', 'payTo' => $this->seller->address(), 'maxTimeoutSeconds' => 60, 'extra' => ['name' => 'USDC', 'version' => '2']];
  }

  private function payload(): array {
    return ['x402Version' => 2, 'accepted' => $this->requirements(), 'payload' => ['signature' => '0x00', 'authorization' => []]];
  }

  private function proof(int $i): array {
    return json_decode(base64_decode(strtr($this->history[$i]['request']->getHeaderLine('Facilitator-Seller-Proof'), '-_', '+/')), TRUE);
  }

  public function testVerifySendsBothObjectsWithAVerifyProof(): void {
    $result = $this->facilitator(new Response(200, [], '{"isValid":true,"payer":"0xbuyer"}'))
      ->verify($this->payload(), $this->requirements(), $this->seller);

    $this->assertTrue($result['isValid']);
    $request = $this->history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame(self::BASE . '/verify', (string) $request->getUri());
    $body = (string) $request->getBody();
    $this->assertSame(['x402Version' => 2, 'paymentPayload' => $this->payload(), 'paymentRequirements' => $this->requirements()], json_decode($body, TRUE));
    $proof = $this->proof(0);
    $this->assertSame($this->seller->address(), $proof['payTo']);
    $this->assertSame('eip155:5042002', $proof['network']);
    $this->assertSame(300, $proof['expiresAt'] - $proof['issuedAt']);
  }

  public function testTheProofSignsTheExactBodySent(): void {
    $this->facilitator(new Response(200, [], '{"success":true,"transaction":"0xtx","network":"eip155:5042002","payer":"0xbuyer"}'))
      ->settle($this->payload(), $this->requirements(), $this->seller);

    $request = $this->history[0]['request'];
    $proof = $this->proof(0);
    // Rebuild what the seller signed and check the signature recovers to it.
    $digest = Eip712::hash(
      ['name' => 'Circle Facilitator Seller Request', 'version' => '1', 'chainId' => 5042002],
      ['SellerRequest' => [
        ['name' => 'purpose', 'type' => 'string'], ['name' => 'method', 'type' => 'string'], ['name' => 'bodyHash', 'type' => 'bytes32'],
        ['name' => 'network', 'type' => 'string'], ['name' => 'payTo', 'type' => 'address'], ['name' => 'nonce', 'type' => 'bytes32'],
        ['name' => 'issuedAt', 'type' => 'uint64'], ['name' => 'expiresAt', 'type' => 'uint64'],
      ]],
      'SellerRequest',
      ['purpose' => 'settle', 'method' => 'POST', 'bodyHash' => Eip712::keccak((string) $request->getBody()), 'network' => 'eip155:5042002', 'payTo' => $this->seller->address(), 'nonce' => $proof['nonce'], 'issuedAt' => $proof['issuedAt'], 'expiresAt' => $proof['expiresAt']],
    );
    $this->assertSame($this->seller->sign($digest), $proof['signature']);
  }

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

  public function testStillPendingAfterPollingIsNotASuccess(): void {
    $pending = '{"paymentId":"pay-1","status":"pending","transaction":null}';
    $result = $this->facilitator(
      new Response(200, [], '{"success":false,"errorReason":"settlement_pending","transaction":"","extensions":{"settlement-status":{"status":"pending","paymentId":"pay-1"}}}'),
      ...array_fill(0, 10, new Response(200, [], $pending)),
    )->settle($this->payload(), $this->requirements(), $this->seller);

    $this->assertFalse($result['success']);
    $this->assertSame('settlement_pending', $result['errorReason']);
  }

  public function testHttpErrorsBecomeFacilitatorExceptionsWithCircleReason(): void {
    $this->expectException(FacilitatorException::class);
    $this->expectExceptionMessage('registration_required');
    $this->facilitator(new Response(403, [], '{"code":403,"message":"Registration required to continue settling on this address","errors":[{"reason":"registration_required"}]}'))
      ->settle($this->payload(), $this->requirements(), $this->seller);
  }

}
