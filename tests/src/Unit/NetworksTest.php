<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\Networks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Prices in dollars become the token's atomic units, exactly.
 */
#[Group('x402_pay')]
final class NetworksTest extends UnitTestCase {

  public static function prices(): array {
    return [
      'a cent' => ['0.01', '10000'],
      'a dollar' => ['1', '1000000'],
      'trailing zeros' => ['1.50', '1500000'],
      'smallest unit' => ['0.000001', '1'],
      'large' => ['123456789.123456', '123456789123456'],
    ];
  }

  #[DataProvider('prices')]
  public function testConvertsDollarsToAtomicUnits(string $usd, string $atomic): void {
    $this->assertSame($atomic, Networks::atomic($usd, 6));
  }

  public static function badPrices(): array {
    return [
      'more decimals than the token' => ['0.0000001'],
      'negative' => ['-1'],
      'zero' => ['0'],
      'zero with decimals' => ['0.000000'],
      'not a number' => ['abc'],
      'empty' => [''],
      'exponent' => ['1e3'],
      'comma' => ['0,01'],
    ];
  }

  #[DataProvider('badPrices')]
  public function testRefusesPricesItCannotChargeExactly(string $usd): void {
    $this->expectException(\InvalidArgumentException::class);
    Networks::atomic($usd, 6);
  }

  public function testRequirementsForArcTestnet(): void {
    $this->assertSame([
      'scheme' => 'exact',
      'network' => 'eip155:5042002',
      'amount' => '10000',
      'asset' => '0x3600000000000000000000000000000000000000',
      'payTo' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
      'maxTimeoutSeconds' => 60,
      'extra' => ['name' => 'USDC', 'version' => '2'],
    ], Networks::requirements('eip155:5042002', '0.01', '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266'));
  }

  public function testTheTimeoutCanBeLonger(): void {
    $this->assertSame(600, Networks::requirements('eip155:5042002', '0.01', '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266', 600)['maxTimeoutSeconds']);
  }

  public function testRefusesAnUnknownNetwork(): void {
    $this->expectException(\InvalidArgumentException::class);
    Networks::requirements('eip155:1', '0.01', '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266');
  }

}
