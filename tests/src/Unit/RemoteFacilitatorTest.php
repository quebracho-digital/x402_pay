<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Tests\UnitTestCase;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\x402_pay\CircleFacilitator;
use Quebracho\X402\Networks;
use Drupal\x402_pay\PaymentGate;
use Quebracho\X402\PaymentHeaders;
use Drupal\x402_pay\PaymentLog;
use Drupal\x402_pay\RemoteFacilitator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Solana through another x402 facilitator, set for the network.
 */
#[Group('x402_pay')]
final class RemoteFacilitatorTest extends UnitTestCase {

  // cspell:disable-next-line
  private const NETWORK = 'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1';
  private const URL = 'https://facilitator.example/x402';
  // cspell:disable-next-line
  private const PAY_TO = '2wKupLR9q6wXYppw8Gr2NvWxKBUqm4PPJKkQfoxHDBg4';
  // cspell:disable-next-line
  private const FEE_PAYER = 'EwWqGE4ZFKLofuestmU4LDdK7XM1N4ALgdZccwYugwGd';
  // cspell:disable-next-line
  private const BUYER = '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM';
  // cspell:disable-next-line
  private const TX = '5VERv8NMvzbJMEkV8xnrLkEaWRtSz9CosKDYjCJjBRnbJLgp8uirBgmQpjKhoR4tjF3ZpRzrFmBV6UjKdiSZkQUW';

  /**
   * The requests sent to the facilitator.
   */
  private array $history = [];

  /**
   * How many times the paid handler ran.
   */
  private int $handlerCalls = 0;

  /**
   * A gate whose Solana devnet facilitator answers these, in order.
   */
  private function gate(?array $remote, Psr7Response ...$responses): PaymentGate {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    $config = $this->getConfigFactoryStub(['x402_pay.settings' => ['facilitator' => ['remote' => $remote ?? []]]]);
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('fac-token');
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->method('getKey')->willReturnCallback(fn($id) => $id === 'fac' ? $key : NULL);
    $remote = new RemoteFacilitator(new Client(['handler' => $stack]), $config, $keys, new MemoryBackend(new Time()));
    // Circle must never be asked about Solana.
    $circle = $this->createMock(CircleFacilitator::class);
    $circle->expects($this->never())->method($this->anything());
    return new PaymentGate($circle, $this->createMock(PaymentLog::class), NULL, NULL, NULL, NULL, $remote);
  }

  /**
   * The facilitator set for Solana devnet.
   */
  private function remote(): array {
    return [self::NETWORK => ['url' => self::URL, 'key_id' => 'fac']];
  }

  /**
   * GET /supported, naming the fee payer.
   */
  private function supported(): Psr7Response {
    $kinds = [
      ['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:84532'],
      ['x402Version' => 2, 'scheme' => 'exact', 'network' => self::NETWORK, 'extra' => ['feePayer' => self::FEE_PAYER]],
    ];
    return new Psr7Response(200, [], json_encode(['kinds' => $kinds]));
  }

  /**
   * A request for the resource, paid with this accepted entry when given.
   */
  private function request(?array $accepted = NULL): Request {
    $request = Request::create('/paid', 'POST');
    if ($accepted !== NULL) {
      $request->headers->set(PaymentHeaders::SIGNATURE, PaymentHeaders::encode([
        'x402Version' => 2,
        'accepted' => $accepted,
        'payload' => ['transaction' => base64_encode('a partially signed transaction')],
      ]));
    }
    return $request;
  }

  /**
   * The handler sold: counts its runs.
   */
  private function handler(): callable {
    return function () {
      $this->handlerCalls++;
      return new JsonResponse(['ok' => TRUE]);
    };
  }

  /**
   * The 402 names the fee payer; verify, run, settle go to the facilitator.
   */
  public function testSolanaPaysThroughItsFacilitator(): void {
    $gate = $this->gate($this->remote(),
      $this->supported(),
      new Psr7Response(200, [], json_encode(['isValid' => TRUE, 'payer' => self::BUYER])),
      new Psr7Response(200, [], json_encode([
        'success' => TRUE,
        'transaction' => self::TX,
        'network' => self::NETWORK,
        'payer' => self::BUYER,
      ])),
    );
    $requirements = Networks::requirements(self::NETWORK, '0.01', self::PAY_TO);

    $asked = $gate->handle($this->request(), $requirements, ['url' => 'https://ombu.ddev.site/paid'], NULL, $this->handler());
    $this->assertSame(402, $asked->getStatusCode());
    $accepts = json_decode((string) $asked->getContent(), TRUE)['accepts'][0];
    $this->assertSame(['feePayer' => self::FEE_PAYER], $accepts['extra']);
    // cspell:disable-next-line
    $this->assertSame('4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU', $accepts['asset']);

    // /supported is cached: the paid request asks only verify and settle.
    $paid = $gate->handle($this->request($accepts), $requirements, ['url' => 'https://ombu.ddev.site/paid'], NULL, $this->handler());
    $this->assertSame(200, $paid->getStatusCode(), (string) $paid->getContent());
    $this->assertSame(1, $this->handlerCalls);
    $this->assertSame(self::TX, PaymentHeaders::decode($paid->headers->get(PaymentHeaders::RESPONSE))['transaction']);

    $sent = array_map(fn($t) => [$t['request']->getMethod(), (string) $t['request']->getUri()], $this->history);
    $this->assertSame([
      ['GET', self::URL . '/supported'],
      ['POST', self::URL . '/verify'],
      ['POST', self::URL . '/settle'],
    ], $sent);
    $this->assertSame('Bearer fac-token', $this->history[1]['request']->getHeaderLine('Authorization'));
    $body = json_decode((string) $this->history[2]['request']->getBody(), TRUE);
    $this->assertSame(self::FEE_PAYER, $body['paymentRequirements']['extra']['feePayer']);
  }

  /**
   * Upfront: settle, then deliver; no verify, and no delivery unpaid.
   */
  public function testUpfrontSettlesBeforeTheHandlerRuns(): void {
    $settled = new Psr7Response(200, [], json_encode([
      'success' => TRUE,
      'transaction' => self::TX,
      'network' => self::NETWORK,
      'payer' => self::BUYER,
    ]));
    $refused = new Psr7Response(400, [], json_encode([
      'success' => FALSE,
      'errorReason' => 'transaction_simulation_failed',
    ]));
    $gate = $this->gate($this->remote(), $this->supported(), $settled, $refused);
    $requirements = Networks::requirements(self::NETWORK, '0.01', self::PAY_TO);
    $requirements['extra']['paymentFlow'] = 'upfront';
    $accepted = ['extra' => ['feePayer' => self::FEE_PAYER] + $requirements['extra']] + $requirements;

    // The handler runs after the settlement, which the buyer gets back.
    $order = [];
    $handler = function () use (&$order) {
      $order[] = 'handler: ' . count($this->history);
      return new JsonResponse(['ok' => TRUE]);
    };
    $paid = $gate->handle($this->request($accepted), $requirements, ['url' => 'https://ombu.ddev.site/paid'], NULL, $handler);
    $this->assertSame(200, $paid->getStatusCode(), (string) $paid->getContent());
    $this->assertSame(self::TX, PaymentHeaders::decode($paid->headers->get(PaymentHeaders::RESPONSE))['transaction']);
    $this->assertSame(['handler: 2'], $order, 'After /supported and /settle.');
    $this->assertSame(self::URL . '/settle', (string) $this->history[1]['request']->getUri());

    // A payment that does not settle delivers nothing.
    $response = $gate->handle($this->request($accepted), $requirements, ['url' => 'https://ombu.ddev.site/paid'], NULL, $handler);
    $this->assertSame(402, $response->getStatusCode());
    $this->assertSame('transaction_simulation_failed', json_decode((string) $response->getContent(), TRUE)['error']);
    $this->assertCount(1, $order);
    $this->assertCount(3, $this->history, 'Never /verify.');
  }

  /**
   * A refusal sent as a 4xx with an x402 body is a refusal, not an outage.
   */
  public function testRefusalInA4xxIsAnAnswer(): void {
    $gate = $this->gate($this->remote(),
      $this->supported(),
      new Psr7Response(400, [], json_encode([
        'isValid' => FALSE,
        'invalidReason' => 'invalid_exact_svm_payload_transaction',
      ])),
    );
    $requirements = Networks::requirements(self::NETWORK, '0.01', self::PAY_TO);
    $accepted = ['extra' => ['feePayer' => self::FEE_PAYER]] + $requirements;
    $response = $gate->handle($this->request($accepted), $requirements, ['url' => 'https://ombu.ddev.site/paid'], NULL, $this->handler());
    $this->assertSame(402, $response->getStatusCode());
    $this->assertSame('invalid_exact_svm_payload_transaction', json_decode((string) $response->getContent(), TRUE)['error']);
    $this->assertSame(0, $this->handlerCalls);
  }

  /**
   * Base58 is case-sensitive: another case is another seller.
   */
  public function testAddressesCompareExactly(): void {
    $gate = $this->gate($this->remote(), $this->supported());
    $requirements = Networks::requirements(self::NETWORK, '0.01', self::PAY_TO);
    $accepted = ['payTo' => strtolower(self::PAY_TO)] + $requirements;
    $response = $gate->handle($this->request($accepted), $requirements, ['url' => 'https://ombu.ddev.site/paid'], NULL, $this->handler());
    $this->assertSame(402, $response->getStatusCode());
    $this->assertSame('The payment does not match the requirements', json_decode((string) $response->getContent(), TRUE)['error']);
    $this->assertCount(1, $this->history, 'Only /supported was asked.');
  }

  /**
   * Without a facilitator for the network, nobody can sponsor the payment.
   */
  public function testNoFacilitatorNoSolana(): void {
    $gate = $this->gate(NULL);
    $response = $gate->handle($this->request(), Networks::requirements(self::NETWORK, '0.01', self::PAY_TO), ['url' => 'https://ombu.ddev.site/paid'], NULL, $this->handler());
    $this->assertSame(502, $response->getStatusCode());
    $this->assertSame([], $this->history);
  }

}
