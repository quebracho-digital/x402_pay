<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\Buyer;
use Drupal\x402_pay\ChainFacilitator;
use Drupal\x402_pay\CircleFacilitator;
use Drupal\x402_pay\Facilitators;
use Drupal\x402_pay\Networks;
use Drupal\x402_pay\RemoteFacilitator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Facilitator;
use Quebracho\X402\Networks as LibraryNetworks;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Signer;

/**
 * The 1.0.0-beta2 API still works, deprecated, on top of quebracho/x402.
 *
 * Each test runs in its own process: the deprecation is raised once, when
 * the old class is first loaded.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class DeprecatedApiTest extends UnitTestCase {

  // Anvil's third test account.
  private const BUYER = '0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a';

  /**
   * The classes that are now the library's own.
   */
  public static function aliases(): array {
    $names = [
      'Signer',
      'Eip712',
      'PaymentHeaders',
      'Transfer',
      'SolanaKey',
      'Rpc',
      'SellerProof',
      'Solana',
      'InvalidPaymentException',
      'FacilitatorException',
    ];
    return array_combine($names, array_map(fn($name) => [$name], $names));
  }

  /**
   * An old class name is the library's class.
   */
  #[DataProvider('aliases')]
  public function testOldNamesAreTheLibraryClasses(string $name): void {
    $this->expectUserDeprecationMessage("Drupal\\x402_pay\\$name is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use \\Quebracho\\X402\\$name instead. See https://www.drupal.org/project/x402_pay/issues/3634901");
    $this->assertSame("Quebracho\\X402\\$name", (new \ReflectionClass("Drupal\\x402_pay\\$name"))->getName());
  }

  /**
   * Networks keeps its list and delegates the rest.
   */
  public function testNetworksKeepsItsList(): void {
    $this->expectUserDeprecationMessage('Drupal\x402_pay\Networks is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use \Quebracho\X402\Networks instead. See https://www.drupal.org/project/x402_pay/issues/3634901');
    // @phpstan-ignore classConstant.deprecatedClass
    $this->assertSame('https://explorer.arc.io/tx/', Networks::ALL['eip155:5042']['explorer']);
    // @phpstan-ignore staticMethod.deprecatedClass
    $this->assertSame(LibraryNetworks::requirements('eip155:5042002', '0.01', '0x70997970C51812dc3A010C7d01b50e0d17dc79C8'), Networks::requirements('eip155:5042002', '0.01', '0x70997970C51812dc3A010C7d01b50e0d17dc79C8'));
    // @phpstan-ignore staticMethod.deprecatedClass
    $this->assertSame('10000', Networks::atomic('0.01', 6));
  }

  /**
   * Buyer pays a 402 with a payment the library accepts.
   */
  public function testBuyerPaysWithTheLibrary(): void {
    $this->expectUserDeprecationMessage('Drupal\x402_pay\Buyer is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use \Quebracho\X402\PayingHttpClient instead. See https://www.drupal.org/project/x402_pay/issues/3634901');
    $req = LibraryNetworks::requirements('eip155:5042002', '0.01', '0x70997970C51812dc3A010C7d01b50e0d17dc79C8');
    $required = ['x402Version' => 2, 'resource' => ['url' => 'https://example.com/a'], 'accepts' => [$req]];
    $receipt = ['success' => TRUE, 'transaction' => '0x' . str_repeat('ab', 32), 'network' => $req['network']];
    $history = [];
    $client = $this->client([
      new Response(402, [PaymentHeaders::REQUIRED => PaymentHeaders::encode($required)]),
      new Response(200, ['Content-Type' => 'text/plain', PaymentHeaders::RESPONSE => PaymentHeaders::encode($receipt)], 'paid'),
    ], $history);

    $approved = NULL;
    // @phpstan-ignore method.deprecatedClass, method.deprecatedClass, new.deprecatedClass
    $result = (new Buyer($client, new Signer(self::BUYER)))->pay('https://example.com/a', NULL, function (array $offer) use (&$approved) {
      $approved = $offer;
    });

    $this->assertSame($req, $approved);
    $this->assertSame([
      'status' => 200,
      'body' => 'paid',
      'contentType' => 'text/plain',
      'tx' => $receipt['transaction'],
      'amount' => '10000',
      'network' => $req['network'],
      'requirements' => $req,
    ], $result);
    $payment = PaymentHeaders::decode($history[1]['request']->getHeaderLine(PaymentHeaders::SIGNATURE));
    $this->assertNull((new EvmExact())->verify($payment, $req), 'The library accepts the payment.');
  }

  /**
   * CircleFacilitator asks Circle through the library's HttpFacilitator.
   */
  public function testCircleFacilitatorDelegates(): void {
    $this->expectUserDeprecationMessage('Drupal\x402_pay\CircleFacilitator is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use \Quebracho\X402\CircleFacilitator instead. See https://www.drupal.org/project/x402_pay/issues/3634901');
    $history = [];
    $client = $this->client([new Response(200, ['Content-Type' => 'application/json'], '{"isValid":true,"payer":"0xabc"}')], $history);
    $req = LibraryNetworks::requirements('eip155:5042002', '0.01', '0x70997970C51812dc3A010C7d01b50e0d17dc79C8');

    // @phpstan-ignore method.deprecatedClass, method.deprecatedClass, new.deprecatedClass
    $answer = (new CircleFacilitator($client))->verify(['x402Version' => 2], $req, new Signer(self::BUYER));

    $this->assertTrue($answer['isValid']);
    // @phpstan-ignore classConstant.deprecatedClass
    $this->assertSame(CircleFacilitator::BASE . '/verify', (string) $history[0]['request']->getUri());
  }

  /**
   * The remote and chain facilitators use the x402_pay.facilitators service.
   */
  public function testRemoteAndChainFacilitatorsDelegate(): void {
    $this->expectUserDeprecationMessage('Drupal\x402_pay\ChainFacilitator is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use the x402_pay.facilitators service or \Quebracho\X402\ChainFacilitator instead. See https://www.drupal.org/project/x402_pay/issues/3634901');
    $req = LibraryNetworks::requirements('eip155:5042002', '0.01', '0x70997970C51812dc3A010C7d01b50e0d17dc79C8');
    $inner = $this->createMock(Facilitator::class);
    $inner->method('settle')->willReturn(['success' => TRUE, 'transaction' => '0x1', 'network' => $req['network']]);
    $facilitators = $this->createMock(Facilitators::class);
    $facilitators->method('settlesItself')->willReturnCallback(fn(string $network) => $network === 'eip155:5042002');
    $facilitators->method('hasRemote')->willReturn(FALSE);
    $facilitators->method('for')->willReturn($inner);
    $container = new ContainerBuilder();
    $container->set('x402_pay.facilitators', $facilitators);
    \Drupal::setContainer($container);
    $args = [new Client(), $this->getConfigFactoryStub(), $this->createMock('Drupal\key\KeyRepositoryInterface')];

    // @phpstan-ignore method.deprecatedClass, new.deprecatedClass
    $chain = new ChainFacilitator(...[...$args, $this->createMock('Drupal\Core\Lock\LockBackendInterface')]);
    // @phpstan-ignore method.deprecatedClass
    $this->assertTrue($chain->handles('eip155:5042002'));
    // @phpstan-ignore method.deprecatedClass
    $this->assertTrue($chain->settle([], $req)['success']);

    // @phpstan-ignore method.deprecatedClass, new.deprecatedClass
    $remote = new RemoteFacilitator(...[...$args, $this->createMock('Drupal\Core\Cache\CacheBackendInterface')]);
    // @phpstan-ignore method.deprecatedClass
    $this->assertFalse($remote->handles('eip155:5042002'));
    $this->expectException('Quebracho\X402\FacilitatorException');
    // @phpstan-ignore method.deprecatedClass
    $remote->verify([], $req);
  }

  /**
   * A Guzzle client that answers $responses and records the requests.
   */
  private function client(array $responses, array &$history): Client {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new Client(['handler' => $stack]);
  }

}
