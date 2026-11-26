<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Functional;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\key\Entity\Key;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The home page for agents: /index.md, and /es.md in each language.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class AgentHomeTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['x402_pay', 'key', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The front page.
   */
  private Node $home;

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
    $this->home = Node::create([
      'type' => 'page',
      'title' => 'Hotel Home',
      'body' => 'Welcome body',
      'field_price' => ['enabled' => 0],
    ]);
    $this->home->save();
    $this->config('system.site')->set('page.front', '/node/' . $this->home->id())->save();
  }

  /**
   * The front page, in its default display while "agent" is not enabled.
   */
  public function testFrontPage(): void {
    $this->drupalGet('index.md');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/markdown');
    $this->assertSession()->responseContains('# Hotel Home');
    $this->assertSession()->responseContains('Welcome body');
    // Nothing is sold: no catalogue.
    $this->assertSession()->responseNotContains('Sold here with x402');
  }

  /**
   * An "agent" display decides what the agent reads.
   */
  public function testAgentViewMode(): void {
    EntityViewMode::create(['id' => 'node.agent', 'label' => 'Agent', 'targetEntityType' => 'node'])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'agent',
      'status' => TRUE,
    ])->removeComponent('body')->save();
    $this->drupalGet('index.md');
    $this->assertSession()->responseContains('# Hotel Home');
    $this->assertSession()->responseNotContains('Welcome body');
  }

  /**
   * Another page chosen for agents, and what the site sells below it.
   */
  public function testChosenPageAndCatalogue(): void {
    $agents = Node::create([
      'type' => 'page',
      'title' => 'For agents',
      'body' => 'How to book',
      'field_price' => ['enabled' => 0],
    ]);
    $agents->save();
    $sold = Node::create(['type' => 'page', 'title' => 'Premium guide', 'field_price' => ['enabled' => 1]]);
    $sold->save();
    $this->config('x402_pay.settings')->set('agent_home.path', '/node/' . $agents->id())->save();
    $this->drupalGet('index.md');
    $this->assertSession()->responseContains('# For agents');
    $this->assertSession()->responseContains('How to book');
    $this->assertSession()->responseNotContains('Welcome body');
    $this->assertSession()->responseContains('## Sold here with x402');
    $this->assertSession()->responseContains('[Premium guide](');
    $this->assertSession()->responseContains('/x402/discovery');
    // Without the catalogue.
    $this->config('x402_pay.settings')->set('agent_home.catalog', FALSE)->save();
    $this->drupalGet('index.md');
    $this->assertSession()->responseNotContains('Premium guide');
  }

  /**
   * A view mode that is sold is linked, never shown free.
   */
  public function testSoldViewModeIsOnlyLinked(): void {
    FieldConfig::loadByName('node', 'page', 'field_price')->setSetting('view_mode', 'agent')->setSetting('canonical', FALSE)->save();
    $this->home->set('field_price', ['enabled' => 1])->save();
    $this->drupalGet('index.md');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Sold with x402: ');
    $this->assertSession()->responseContains('/x402/node/' . $this->home->id() . '/agent');
    $this->assertSession()->responseNotContains('Welcome body');
  }

  /**
   * A page sold on its own URL, or in full, is not free at /index.md.
   *
   * The home page shows the page in another display, but the fields sold
   * there are the same: it links to the product instead.
   */
  public function testPageSoldElsewhereIsOnlyLinked(): void {
    // Sold on its own URL (the field's defaults: full, canonical).
    $this->home->set('field_price', ['enabled' => 1])->save();
    $this->drupalGet('index.md');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('Welcome body');
    $this->assertSession()->responseContains('Sold with x402: ');
    // Sold in full at its x402 URL only.
    FieldConfig::loadByName('node', 'page', 'field_price')->setSetting('canonical', FALSE)->save();
    $this->drupalGet('index.md');
    $this->assertSession()->responseNotContains('Welcome body');
    $this->assertSession()->responseContains('/x402/node/' . $this->home->id() . '/full');
  }

  /**
   * A view mode sold without a display of its own is not free here.
   *
   * It falls back to the default display, the one shown here: same fields.
   */
  public function testSoldModeWithoutDisplayIsOnlyLinked(): void {
    EntityViewMode::create(['id' => 'node.premium', 'label' => 'Premium', 'targetEntityType' => 'node'])->save();
    FieldConfig::loadByName('node', 'page', 'field_price')->setSetting('view_mode', 'premium')->setSetting('canonical', FALSE)->save();
    $this->home->set('field_price', ['enabled' => 1])->save();
    $this->drupalGet('index.md');
    $this->assertSession()->responseNotContains('Welcome body');
    $this->assertSession()->responseContains('/x402/node/' . $this->home->id() . '/premium');
    // And on the page's own .md.
    $this->drupalGet('node/' . $this->home->id() . '.md');
    $this->assertSession()->statusCodeEquals(402);
  }

  /**
   * A front page set by its alias (/home), and a chosen page by alias.
   */
  public function testAliases(): void {
    $this->container->get('module_installer')->install(['path']);
    $this->rebuildContainer();
    $this->container->get('entity_type.manager')->getStorage('path_alias')->create([
      'path' => '/node/' . $this->home->id(),
      'alias' => '/home',
    ])->save();
    $this->config('system.site')->set('page.front', '/home')->save();
    $this->drupalGet('index.md');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('# Hotel Home');
    // The same alias as the page chosen for agents.
    $this->config('system.site')->set('page.front', '/user/login')->save();
    $this->config('x402_pay.settings')->set('agent_home.path', '/home')->save();
    $this->drupalGet('index.md');
    $this->assertSession()->responseContains('# Hotel Home');
  }

  /**
   * Each language's prefix plus ".md"; any other ".md" is not the home page.
   */
  public function testLanguages(): void {
    $this->container->get('module_installer')->install(['language']);
    $this->rebuildContainer();
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->drupalGet('es.md');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('# Hotel Home');
    $this->drupalGet('xx.md');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * With Markdownify, /index.md is still ours: it has no front page.
   */
  public function testWithMarkdownify(): void {
    $markdownify = $this->container->get('extension.list.module')->getList()['markdownify'] ?? NULL;
    if (!$markdownify || !empty($markdownify->info['core_incompatible'])) {
      $this->markTestSkipped('Markdownify is not available for this Drupal version.');
    }
    $this->container->get('module_installer')->install(['markdownify']);
    $this->rebuildContainer();
    $this->drupalGet('index.md');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('# Hotel Home');
  }

}
