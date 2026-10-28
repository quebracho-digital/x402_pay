<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\x402_pay\ClaimStore;
use PHPUnit\Framework\Attributes\Group;

/**
 * A payment is claimed once; a release makes it claimable again.
 */
#[Group('x402_pay')]
final class ClaimStoreTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['key', 'x402_pay'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('x402_pay', ['x402_pay_claim']);
  }

  /**
   * The first claim wins, the second loses, a release frees it.
   */
  public function testAPaymentIsClaimedOnce(): void {
    $claims = new ClaimStore($this->container->get('database'), $this->container->get('datetime.time'));
    $key = hash('sha256', 'payment');
    $this->assertTrue($claims($key));
    $this->assertFalse($claims($key));
    $claims->release($key);
    $this->assertTrue($claims($key));
  }

}
