<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Pause payments page: who may use it, and that it pauses and resumes.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class StopFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['x402_pay', 'key', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The tabs between the settings and this page.
    $this->drupalPlaceBlock('local_tasks_block');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // The flag lives outside the test database.
    \Drupal::service('x402_pay.stop')->release();
    parent::tearDown();
  }

  /**
   * Pausing has its own permission; the settings' does not grant it.
   */
  public function testOnlyThePausePermissionOpensThePage(): void {
    $this->drupalGet('admin/config/services/x402/stop');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer x402 pay']));
    $this->drupalGet('admin/config/services/x402/stop');
    $this->assertSession()->statusCodeEquals(403);
    // And the settings page offers no tab to it.
    $this->drupalGet('admin/config/services/x402');
    $this->assertSession()->linkNotExists('Pause payments');

    // On call: may pause, may not change where the money goes.
    $this->drupalLogin($this->drupalCreateUser(['pause x402 payments']));
    $this->drupalGet('admin/config/services/x402/stop');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('admin/config/services/x402');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The page pauses with a reason, shows both switches, and resumes.
   */
  public function testPausesAndResumes(): void {
    $stop = \Drupal::service('x402_pay.stop');
    $user = $this->drupalCreateUser(['pause x402 payments', 'administer x402 pay']);
    $this->drupalLogin($user);
    $this->drupalGet('admin/config/services/x402');
    $this->clickLink('Pause payments');
    $this->assertSession()->pageTextContains('Running.');
    $this->assertSession()->buttonNotExists('Resume payments');

    $this->submitForm(['why' => 'facilitator incident'], 'Pause payments');
    $this->assertSession()->pageTextContains('Payments are paused.');
    $this->assertSession()->pageTextContains('PAUSED. Nothing is sold or bought.');
    $this->assertSession()->pageTextContains("The library's switch (a flag file): engaged");
    $this->assertSession()->pageTextContains("The module's switch (the database): engaged");
    $this->assertSession()->pageTextContains('by user ' . $user->id() . ': facilitator incident');
    $this->assertSession()->buttonNotExists('Pause payments');
    $this->assertSame(['library' => TRUE, 'module' => TRUE], $stop->layers());
    $this->assertSame($user->id(), (string) $stop->note()['uid']);

    $this->submitForm([], 'Resume payments');
    $this->assertSession()->pageTextContains('Payments resumed.');
    $this->assertSession()->pageTextContains('Running.');
    \Drupal::state()->resetCache();
    $this->assertSame(['library' => FALSE, 'module' => FALSE], $stop->layers());
  }

}
