<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\x402_pay\PaymentGate;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The module's services and the payment log table, inside Drupal.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class PaymentLogTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['x402_pay', 'key'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('x402_pay', ['x402_pay_log']);
  }

  /**
   * The gate is service.
   */
  public function testTheGateIsService(): void {
    $this->assertInstanceOf(PaymentGate::class, $this->container->get('x402_pay.gate'));
  }

  /**
   * Records payments.
   */
  public function testRecordsPayments(): void {
    $this->container->get('x402_pay.log')->record([
      'resource' => 'https://ombu.ddev.site/product/1/call',
      'network' => 'eip155:5042002',
      'amount' => '100',
      'pay_to' => '0x7e510E6B4c3E2121CB64CD0686a4f241Df326510',
      'payer' => '0xcb2d7f50D917FeD02b82ef08cEfBF9827463B794',
      'status' => 'settled',
      'transaction' => '0x2189f224626f989efef1c28f8f72a6ea205805f7394313139ddc2f51b027674d',
      'handler_status' => 200,
    ]);
    $row = \Drupal::database()->select('x402_pay_log', 'l')->fields('l')->execute()->fetchAssoc();
    $this->assertSame('settled', $row['status']);
    $this->assertSame('100', $row['amount']);
    $this->assertSame('200', (string) $row['handler_status']);
    $this->assertNull($row['error']);
    $this->assertGreaterThan(0, (int) $row['created']);
  }

}
