<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A module answers its own page's ".md" with a route in the markdown format.
 *
 * The same way with Markdownify or without it, and never the HTML page
 * under a ".md" name.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class MarkdownRouteTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['x402_pay', 'x402_pay_home_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Without Markdownify: our filter gives ".md" the markdown format.
   */
  public function testWithoutMarkdownify(): void {
    $this->assertMarkdownRoute();
  }

  /**
   * With Markdownify: its own reading of ".md", the same result.
   */
  public function testWithMarkdownify(): void {
    $markdownify = $this->container->get('extension.list.module')->getList()['markdownify'] ?? NULL;
    if (!$markdownify || !empty($markdownify->info['core_incompatible'])) {
      $this->markTestSkipped('Markdownify is not available for this Drupal version.');
    }
    $this->container->get('module_installer')->install(['markdownify']);
    $this->rebuildContainer();
    $this->assertFalse($this->container->has('x402_pay.markdown_format_route_filter'));
    $this->assertMarkdownRoute();
  }

  /**
   * The page, its markdown, and a page without one.
   */
  private function assertMarkdownRoute(): void {
    $this->drupalGet('x402-test/page');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Test page in HTML');
    $this->drupalGet('x402-test/page.md');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/markdown');
    $this->assertSession()->responseContains('# Test page');
    // A page without a markdown route is not its HTML under a .md name.
    $this->drupalGet('user/login.md');
    $this->assertSession()->statusCodeNotEquals(200);
  }

}
