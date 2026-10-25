<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\key\Entity\Key;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * On routes other than an entity's page, what is sold is still never free.
 *
 * Markdownify's (/markdownify/node/N, and the node's path in the markdown
 * format: .md, ?_format=markdown, Accept), JSON:API, a revision's page.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class MarkdownifyTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'x402_pay',
    'key',
    'node',
    'page_cache',
    'dynamic_page_cache',
    'jsonapi',
    'serialization',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * What a browser sends.
   */
  private const BROWSER = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'page']);
    Key::create([
      'id' => 'seller',
      'label' => 'Seller',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80'],
    ])->save();
    FieldStorageConfig::create(['field_name' => 'field_price', 'entity_type' => 'node', 'type' => 'x402_price'])->save();
    FieldConfig::create([
      'field_name' => 'field_price',
      'entity_type' => 'node',
      'bundle' => 'page',
      'settings' => ['key_id' => 'seller'],
    ])->save();
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('access content')->save();
    // JSON:API's routes for the new bundle.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Installs Markdownify, or skips the test where it cannot be.
   */
  private function markdownify(): void {
    // A require-dev that may not be there yet for the next Drupal major.
    $markdownify = $this->container->get('extension.list.module')->getList()['markdownify'] ?? NULL;
    if (!$markdownify || !empty($markdownify->info['core_incompatible'])) {
      $this->markTestSkipped('Markdownify is not available for this Drupal version.');
    }
    $this->container->get('module_installer')->install(['markdownify']);
    $this->rebuildContainer();
  }

  /**
   * Sold markdown is charged on every route Markdownify answers.
   */
  public function testWhatIsSoldIsChargedOnMarkdownifyRoutesToo(): void {
    $this->markdownify();
    $sold = Node::create(['type' => 'page', 'title' => 'Terms of X', 'field_price' => ['enabled' => 1]]);
    $sold->save();
    $path = 'node/' . $sold->id();
    foreach ([
      "$path.md" => self::BROWSER,
      "markdownify/$path" => self::BROWSER,
      $path => 'text/markdown,' . self::BROWSER,
      'jsonapi/node/page/' . $sold->uuid() => self::BROWSER,
    ] as $url => $accept) {
      $this->drupalGet($url, [], ['Accept' => $accept]);
      $this->assertSame(402, $this->getSession()->getStatusCode(), $url);
    }
    // Markdownify 1.2.0 answers ?_format=markdown 404 even alone: never 200.
    $this->drupalGet("$path?_format=markdown", [], ['Accept' => self::BROWSER]);
    $this->assertNotEquals(200, $this->getSession()->getStatusCode());
    // People still see the page.
    $this->drupalGet($path, [], ['Accept' => self::BROWSER]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/html');
  }

  /**
   * A free page reads as markdown, and the page cache keeps HTML apart.
   */
  public function testFreeMarkdownWorksAndStaysOutOfTheHtmlCache(): void {
    $this->markdownify();
    $free = Node::create([
      'type' => 'page',
      'title' => 'Free notes',
      'body' => 'Free body',
      'field_price' => ['enabled' => 0],
    ]);
    $free->save();
    $path = 'node/' . $free->id();
    $this->drupalGet($path, [], ['Accept' => self::BROWSER]);
    $this->drupalGet($path, [], ['Accept' => self::BROWSER]);
    foreach (["$path.md", "markdownify/$path"] as $url) {
      $this->drupalGet($url, [], ['Accept' => self::BROWSER]);
      $this->assertSame(200, $this->getSession()->getStatusCode(), $url);
      $this->assertStringContainsString('text/markdown', (string) $this->getSession()->getResponseHeader('Content-Type'), $url);
      $this->assertStringContainsString('Free body', $this->getSession()->getPage()->getContent(), $url);
    }
    $this->drupalGet($path, [], ['Accept' => self::BROWSER]);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/html');
  }

  /**
   * What a visitor may not see is neither sold nor converted.
   */
  public function testUnpublishedIsDenied(): void {
    $this->markdownify();
    $draft = Node::create(['type' => 'page', 'title' => 'Draft', 'status' => 0, 'field_price' => ['enabled' => 1]]);
    $draft->save();
    $path = 'node/' . $draft->id();
    foreach (["$path.md", "markdownify/$path", "$path?_format=markdown"] as $url) {
      $this->drupalGet($url, [], ['Accept' => self::BROWSER]);
      // Denied (403), or not found as markdown (404): never the draft.
      $this->assertContains($this->getSession()->getStatusCode(), [403, 404], $url);
      $this->assertStringNotContainsString('Draft', $this->getSession()->getPage()->getContent(), $url);
    }
  }


  /**
   * Another page showing what is sold is not cached for an agent either.
   */
  public function testARevisionPageIsNeverCachedForAgents(): void {
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('view all revisions')->save();
    $sold = Node::create(['type' => 'page', 'title' => 'Terms of X', 'field_price' => ['enabled' => 1]]);
    $sold->save();
    $first = $sold->getRevisionId();
    $sold->setNewRevision();
    $sold->save();
    $url = 'node/' . $sold->id() . "/revisions/$first/view";
    for ($i = 0; $i < 2; $i++) {
      $this->drupalGet($url, [], ['Accept' => self::BROWSER]);
      $this->assertSession()->statusCodeEquals(200);
      // Neither the page cache nor a CDN keeps it.
      $this->assertNotEquals('HIT', $this->getSession()->getResponseHeader('X-Drupal-Cache'));
      $this->assertSession()->responseHeaderContains('Cache-Control', 'private');
    }
    $this->drupalGet($url, [], ['Accept' => '*/*']);
    $this->assertSession()->statusCodeEquals(402);
  }

}
