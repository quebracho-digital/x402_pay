<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\ModuleNetworks;
use Drupal\x402_pay\PaymentGate;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Drupal\x402_pay\Reconciler;
use Drupal\x402_pay\Sweeper;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Quebracho\X402\PaymentHeaders;
use Symfony\Component\HttpFoundation\Request;

/**
 * Values read from outside are taken in one spelling, whole.
 *
 * A PCRE `$` also matches before a final newline, so "0xabc…\n" passed the
 * module's own format checks. The library reads every field through
 * Quebracho\X402\Field since 0.5.1; the module does the same.
 */
#[Group('x402_pay')]
final class StrictFieldsTest extends UnitTestCase {

  private const ADDRESS = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
  private const NONCE = '0x1111111111111111111111111111111111111111111111111111111111111111';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ModuleNetworks::register();
  }

  /**
   * What may follow a value and must not pass.
   */
  public static function tails(): array {
    return ['a newline' => ["\n"], 'a space' => [' '], 'CRLF' => ["\r\n"], 'a NUL' => ["\0"]];
  }

  /**
   * The gate logs who signed and which nonce: only as the chain spells them.
   */
  #[DataProvider('tails')]
  public function testTheGateReadsNoAuthorizationFieldWithTail(string $tail): void {
    $read = function (array $authorization): array {
      $request = Request::create('/paid');
      $request->headers->set(PaymentHeaders::SIGNATURE, PaymentHeaders::encode(['payload' => ['authorization' => $authorization]]));
      $gate = (new \ReflectionClass(PaymentGate::class))->newInstanceWithoutConstructor();
      return (new \ReflectionMethod(PaymentGate::class, 'authorization'))->invoke($gate, $request);
    };
    $good = ['from' => self::ADDRESS, 'nonce' => self::NONCE, 'validBefore' => '1800000000'];

    $this->assertSame(['from' => self::ADDRESS, 'nonce' => self::NONCE, 'validBefore' => 1800000000], $read($good));
    foreach (array_keys($good) as $field) {
      $this->assertArrayNotHasKey($field, $read([$field => $good[$field] . $tail] + $good), $field);
    }
  }

  /**
   * A log row whose nonce or payer is not one is not asked about on chain.
   */
  #[DataProvider('tails')]
  public function testTheReconcilerAsksTheChainAboutNoFieldWithTail(string $tail): void {
    // Built without its HTTP client: asking the chain at all would fail.
    $reconciler = (new \ReflectionClass(Reconciler::class))->newInstanceWithoutConstructor();
    $spent = new \ReflectionMethod(Reconciler::class, 'spent');

    foreach ([['nonce' => self::NONCE . $tail], ['payer' => self::ADDRESS . $tail]] as $bad) {
      $row = (object) ($bad + ['nonce' => self::NONCE, 'payer' => self::ADDRESS, 'network' => 'eip155:5042002']);
      $this->assertFalse($spent->invoke($reconciler, $row));
    }
  }

  /**
   * The cold wallet of the sweep is an address, whole.
   */
  #[DataProvider('tails')]
  public function testTheSweeperSendsToNoAddressWithTail(string $tail): void {
    $sweep = ['to' => self::ADDRESS . $tail, 'keys' => [], 'min' => '0.01'];
    $config = $this->getConfigFactoryStub(['x402_pay.settings' => ['sweep' => $sweep]]);
    $sweeper = new Sweeper(new Client(), new HttpFactory(), $config, $this->createMock(KeyRepositoryInterface::class), $this->createMock(StateInterface::class), $this->createMock(TimeInterface::class), new NullLogger());

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No cold wallet to sweep to.');
    $sweeper->sweep(FALSE);
  }

  /**
   * A Solana address is base58, whole.
   */
  #[DataProvider('tails')]
  public function testSolanaAddressHasNoTail(string $tail): void {
    $mint = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
    $this->assertTrue(X402PriceItem::isSolanaAddress($mint));
    $this->assertFalse(X402PriceItem::isSolanaAddress($mint . $tail));
  }

}
