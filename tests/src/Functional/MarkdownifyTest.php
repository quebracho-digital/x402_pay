<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\key\Entity\Key;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
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

  use EntityReferenceFieldCreationTrait;

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
   * JSON:API's lists, includes and relationships omit what is sold.
   */
  public function testJsonApiOmitsWhatIsSoldBesideItsResource(): void {
    $this->createEntityReferenceField('node', 'page', 'field_ref', 'Ref', 'node');
    $this->container->get('router.builder')->rebuild();
    $sold = Node::create([
      'type' => 'page',
      'title' => 'Terms of X',
      'body' => 'Sold body',
      'field_price' => ['enabled' => 1],
    ]);
    $sold->save();
    $free = Node::create([
      'type' => 'page',
      'title' => 'Free notes',
      'body' => 'Free body',
      'field_price' => ['enabled' => 0],
      'field_ref' => $sold,
    ]);
    $free->save();
    $api = ['Accept' => 'application/vnd.api+json'];
    $free_url = 'jsonapi/node/page/' . $free->uuid();
    foreach ([
      'jsonapi/node/page' => [],
      $free_url => ['include' => 'field_ref'],
      "$free_url/field_ref" => [],
    ] as $url => $query) {
      $this->drupalGet($url, ['query' => $query], $api);
      $this->assertSame(200, $this->getSession()->getStatusCode(), $url);
      $this->assertStringNotContainsString('Sold body', $this->getSession()->getPage()->getContent(), $url);
    }
    $this->drupalGet('jsonapi/node/page', [], $api);
    $this->assertStringContainsString('Free body', $this->getSession()->getPage()->getContent());
    // Nor told by filters or sorts, match by match.
    foreach ([
      ['filter' => ['body.value' => ['operator' => 'CONTAINS', 'value' => 'Sold']]],
      ['filter' => ['field_ref.body.value' => ['operator' => 'CONTAINS', 'value' => 'Sold']]],
      ['sort' => 'body.value'],
    ] as $query) {
      $this->drupalGet('jsonapi/node/page', ['query' => $query], $api);
      $this->assertNotEquals(200, $this->getSession()->getStatusCode(), http_build_query($query));
    }
    // Its own resource is still for sale.
    $this->drupalGet('jsonapi/node/page/' . $sold->uuid(), [], $api);
    $this->assertSession()->statusCodeEquals(402);
    // Nor in what a write answers.
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save(TRUE);
    $this->drupalLogin($this->drupalCreateUser(['access content', 'edit any page content']));
    $client = $this->getHttpClient();
    $token = (string) $client->get($this->buildUrl('session/token'), ['cookies' => $this->getSessionCookies()])->getBody();
    $patch = $client->request('PATCH', $this->buildUrl($free_url, ['query' => ['include' => 'field_ref']]), [
      'cookies' => $this->getSessionCookies(),
      'headers' => [
        'Content-Type' => 'application/vnd.api+json',
        'Accept' => 'application/vnd.api+json',
        'X-CSRF-Token' => $token,
      ],
      'body' => json_encode([
        'data' => ['type' => 'node--page', 'id' => $free->uuid(), 'attributes' => ['title' => 'Free notes 2']],
      ]),
      'http_errors' => FALSE,
    ]);
    $this->assertSame(200, $patch->getStatusCode(), (string) $patch->getBody());
    $this->assertStringNotContainsString('Sold body', (string) $patch->getBody());
    // Who reads it free reads it free here too.
    $this->drupalLogin($this->drupalCreateUser(['access content', 'access x402 content free']));
    $this->drupalGet('jsonapi/node/page', ['query' => ['filter' => ['body.value' => 'Sold body']]], $api);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringContainsString('Sold body', $this->getSession()->getPage()->getContent());
    $this->drupalLogout();
    // A draft's, which JSON:API's route does not check, is not offered.
    $draft = Node::create(['type' => 'page', 'title' => 'Draft', 'status' => 0, 'field_price' => ['enabled' => 1]]);
    $draft->save();
    $this->drupalGet('jsonapi/node/page/' . $draft->uuid(), [], $api);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertStringNotContainsString('Draft', implode(' ', $this->getSession()->getResponseHeaders()['payment-required'] ?? []) . $this->getSession()->getPage()->getContent());
  }

  /**
   * Another page showing what is sold is not cached for an agent either.
   */
  public function testRevisionPagesAreNeverCachedForAgents(): void {
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
