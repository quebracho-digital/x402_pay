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
 * The sections other modules add to the home page for agents.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class AgentHomeEventTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['x402_pay', 'key', 'node', 'x402_pay_home_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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
    FieldStorageConfig::create([
      'field_name' => 'field_price',
      'entity_type' => 'node',
      'type' => 'x402_price',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_price',
      'entity_type' => 'node',
      'bundle' => 'page',
      'settings' => ['key_id' => 'seller'],
    ])->save();
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('access content')->save();
    $home = Node::create([
      'type' => 'page',
      'title' => 'Hotel Home',
      'body' => 'Welcome body',
      'field_price' => ['enabled' => 0],
    ]);
    $home->save();
    $this->config('system.site')->set('page.front', '/node/' . $home->id())->save();
    // One product, so the catalogue (weight 10) appears too.
    $sold = Node::create([
      'type' => 'page',
      'title' => 'Premium guide',
      'field_price' => ['enabled' => 1],
    ]);
    $sold->save();
  }

  /**
   * Sections from other modules go between the page and the catalogue.
   */
  public function testSectionsInWeightOrder(): void {
    $this->drupalGet('index.md');
    $this->assertSession()->statusCodeEquals(200);
    $content = $this->getSession()->getPage()->getContent();
    $before = strpos($content, '## Before everything');
    $page = strpos($content, '# Hotel Home');
    $book = strpos($content, '## Book a room');
    $sold = strpos($content, '## Sold here with x402');
    $this->assertNotFalse($before);
    $this->assertNotFalse($page);
    $this->assertNotFalse($book);
    $this->assertNotFalse($sold);
    $this->assertLessThan($page, $before);
    $this->assertLessThan($book, $page);
    $this->assertLessThan($sold, $book);
    // The section got the page's language.
    $this->assertStringContainsString('langcode: en', $content);
  }

}
