<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\x402_pay\Hook\X402PayHooks;
use Quebracho\X402\AddressRejected;
use Quebracho\X402\OfacSdn;

/**
 * Tests that the library's events reach Drupal's log.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class LibraryEventLogTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['x402_pay', 'key', 'dblog'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
    $this->installSchema('x402_pay', ['x402_pay_log', 'x402_pay_claim']);
    $this->installConfig(['x402_pay']);
  }

  /**
   * A refused address is a line in the x402_pay channel.
   */
  public function testRejectedAddressIsLoggedByTheDrupalChannel(): void {
    $this->container->get('event_dispatcher')->dispatch(new AddressRejected('payer', 'eip155:8453', '0x1111111111111111111111111111111111111111', 'address_sanctioned'));
    $row = \Drupal::database()->select('watchdog', 'w')->fields('w', ['type', 'message', 'severity', 'variables'])->condition('type', 'x402_pay')->execute()->fetchAssoc();
    $this->assertNotFalse($row, 'The event produced no log line.');
    $this->assertStringContainsString('address rejected', $row['message']);
    $this->assertStringContainsString('address_sanctioned', $row['variables']);
    $this->assertStringContainsString('0x1111111111111111111111111111111111111111', $row['variables']);
  }

  /**
   * The sale reads a list that has the shipped one as its floor.
   */
  public function testSalesListIsTheShippedOneWithTheSiteSnapshot(): void {
    $list = $this->container->get('x402_pay.ofac_sdn');
    $this->assertInstanceOf(OfacSdn::class, $list);
    $this->assertNotSame('', $list->published(), 'Even without a snapshot the shipped list is in use.');
  }

  /**
   * The status report shows how old the sanctions list in use is.
   */
  public function testTheStatusReportShowsTheListAge(): void {
    $requirements = $this->container->get(X402PayHooks::class)->runtimeRequirements();
    $this->assertArrayHasKey('x402_pay_ofac', $requirements);
    $this->assertStringContainsString('published', (string) $requirements['x402_pay_ofac']['value']);
  }

}
