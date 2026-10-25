<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\key\Entity\Key;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What only a real request through the UI shows.
 *
 * The page cache never hands a paid entity's page to an agent: a functional
 * test because the page cache ignores command-line requests. And the field
 * settings form refuses a field that sells what another already sells.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class PageCacheTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'x402_pay',
    'key',
    'block',
    'entity_test',
    'page_cache',
    'dynamic_page_cache',
    'field_ui',
    'jsonapi',
    'serialization',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Key::create([
      'id' => 'seller',
      'label' => 'Seller',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80'],
    ])->save();
    FieldStorageConfig::create(['field_name' => 'field_price', 'entity_type' => 'entity_test', 'type' => 'x402_price'])->save();
    FieldConfig::create([
      'field_name' => 'field_price',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'settings' => ['key_id' => 'seller'],
    ])->save();
  }

  /**
   * Repeated browser visits do not cache the page for agents.
   */
  public function testRepeatedBrowserVisitsDoNotCacheThePageForAgents(): void {
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('view test entity')->save();
    $entity = EntityTest::create(['name' => 'Terms of X', 'field_price' => ['enabled' => 1]]);
    $entity->save();

    // The second visit renders from the render cache, which skips view hooks.
    for ($i = 0; $i < 3; $i++) {
      $this->drupalGet($entity->toUrl(), [], ['Accept' => 'text/html']);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->responseHeaderNotEquals('X-Drupal-Cache', 'HIT');
      $this->assertSession()->responseHeaderContains('Link', '/x402/entity_test/' . $entity->id());
    }
    $this->drupalGet($entity->toUrl(), [], ['Accept' => '*/*']);
    $this->assertSession()->statusCodeEquals(402);
  }

  /**
   * Markdown comes from the gate alone.
   *
   * Sold entities charge on every route that shows them, free ones are
   * converted, with the caches warm.
   */
  public function testMarkdownComesFromTheGateAlone(): void {
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('view test entity')->save();
    $free = EntityTest::create(['name' => 'Free notes', 'field_price' => ['enabled' => 0]]);
    $free->save();
    $sold = EntityTest::create(['name' => 'Terms of X', 'field_price' => ['enabled' => 1]]);
    $sold->save();
    // Browsers first: the page cache holds whatever it may.
    foreach ([$free, $sold] as $entity) {
      $this->drupalGet($entity->toUrl(), [], ['Accept' => 'text/html']);
      $this->drupalGet($entity->toUrl(), [], ['Accept' => 'text/html']);
    }
    $this->assertSession()->responseHeaderContains('Vary', 'Accept');

    $this->drupalGet($free->toUrl(), [], ['Accept' => 'text/markdown']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/markdown');
    $this->assertStringStartsWith('# Free notes', $this->getSession()->getPage()->getContent());
    // And the page cache did not keep the markdown for browsers.
    $this->drupalGet($free->toUrl(), [], ['Accept' => 'text/html']);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/html');
    $this->assertSession()->responseHeaderContains('Link', 'type="text/markdown"');

    // Named in <head> and in a Link header, at its own URL plus .md.
    $md = $free->toUrl()->toString() . '.md';
    $this->assertSession()->elementExists('css', 'link[rel="alternate"][type="text/markdown"][href="' . $md . '"]');
    $this->assertSession()->responseHeaderContains('Link', $md);
    // Which answers agents that send no Accept, twice: no cache keeps it.
    for ($i = 0; $i < 2; $i++) {
      $this->drupalGet($md, [], ['Accept' => 'text/html']);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->responseHeaderContains('Content-Type', 'text/markdown');
      $this->assertStringStartsWith('# Free notes', $this->getSession()->getPage()->getContent());
    }
    // The link people see: a block, through a template themes override.
    $this->drupalPlaceBlock('x402_pay_markdown_link');
    $this->drupalGet($free->toUrl());
    $this->assertSession()->linkByHrefExists($md);
    $this->drupalGet($sold->toUrl(), [], ['Accept' => 'text/html']);
    $this->assertSession()->linkByHrefNotExists($sold->toUrl()->toString() . '.md');
    $this->assertSession()->elementNotExists('css', 'link[type="text/markdown"]');
    // A .md path is never an HTML page.
    $this->drupalGet('user/login.md', [], ['Accept' => 'text/html']);
    $this->assertSession()->statusCodeEquals(404);

    // Sold: by Accept, by .md, by ?_format=, and through JSON:API, never free.
    $this->drupalGet($sold->toUrl()->toString() . '.md', [], ['Accept' => 'text/html']);
    $this->assertSession()->statusCodeEquals(402);
    $this->drupalGet($sold->toUrl(), [], ['Accept' => 'text/markdown']);
    $this->assertSession()->statusCodeEquals(402);
    $this->drupalGet($sold->toUrl('canonical', ['query' => ['_format' => 'markdown']]), [], ['Accept' => '*/*']);
    $this->assertNotEquals(200, $this->getSession()->getStatusCode());
    $this->drupalGet('jsonapi/entity_test/entity_test/' . $sold->uuid(), [], ['Accept' => 'application/vnd.api+json']);
    $this->assertSession()->statusCodeEquals(402);
    $this->drupalGet('jsonapi/entity_test/entity_test/' . $free->uuid(), [], ['Accept' => 'application/vnd.api+json']);
    $this->assertSession()->statusCodeEquals(200);

    // What a visitor cannot see is neither sold nor converted.
    Role::load(AccountInterface::ANONYMOUS_ROLE)->revokePermission('view test entity')->save();
    $this->drupalGet($free->toUrl(), [], ['Accept' => 'text/markdown']);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($sold->toUrl(), [], ['Accept' => 'text/markdown']);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The settings form refuses what another field sells.
   */
  public function testTheSettingsFormRefusesWhatAnotherFieldSells(): void {
    FieldStorageConfig::create(['field_name' => 'field_summary', 'entity_type' => 'entity_test', 'type' => 'x402_price'])->save();
    FieldConfig::create([
      'field_name' => 'field_summary',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'settings' => ['key_id' => 'seller', 'view_mode' => 'teaser', 'canonical' => FALSE],
    ])->save();
    $this->drupalLogin($this->drupalCreateUser(['administer entity_test fields', 'administer entity_test content']));
    $form = 'entity_test/structure/entity_test/fields/entity_test.entity_test.field_summary';

    $this->drupalGet($form);
    $this->submitForm(['settings[view_mode]' => 'full'], 'Save');
    $this->assertSession()->pageTextContains('already sells the full view mode');

    $this->drupalGet($form);
    $this->submitForm(['settings[canonical]' => TRUE], 'Save');
    $this->assertSession()->pageTextContains("already charges on the entity's own URL");

    $this->drupalGet($form);
    $this->submitForm(['settings[amount]' => '0.002'], 'Save');
    $this->assertSession()->pageTextNotContains('already');
    $this->assertSame('0.002', FieldConfig::loadByName('entity_test', 'entity_test', 'field_summary')->getSetting('amount'));
  }

}
