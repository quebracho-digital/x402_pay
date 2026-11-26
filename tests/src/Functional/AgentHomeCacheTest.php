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
 * The home page for agents is cached by what its page shows, per visitor.
 *
 * What the page renders (a referenced entity, its access for this user)
 * goes with the response: the dynamic page cache never hands one visitor's
 * page to another, nor a stale one.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class AgentHomeCacheTest extends BrowserTestBase {

  use EntityReferenceFieldCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['x402_pay', 'key', 'node', 'dynamic_page_cache'];

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
    FieldStorageConfig::create(['field_name' => 'field_price', 'entity_type' => 'node', 'type' => 'x402_price'])->save();
    FieldConfig::create([
      'field_name' => 'field_price',
      'entity_type' => 'node',
      'bundle' => 'page',
      'settings' => ['key_id' => 'seller'],
    ])->save();
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('access content')->save();
    $this->createEntityReferenceField('node', 'page', 'field_ref', 'Ref', 'node');
    $this->container->get('entity_display.repository')->getViewDisplay('node', 'page', 'default')
      ->setComponent('field_ref', ['type' => 'entity_reference_label'])
      ->save();
  }

  /**
   * A draft one user may see is not cached for another with the same role.
   */
  public function testReferencedDraftIsNotSharedBetweenUsers(): void {
    $a = $this->drupalCreateUser(['access content', 'view own unpublished content']);
    $b = $this->drupalCreateUser(['access content', 'view own unpublished content']);
    $draft = Node::create([
      'type' => 'page',
      'title' => 'Draft of A',
      'status' => 0,
      'uid' => $a->id(),
      'field_price' => ['enabled' => 0],
    ]);
    $draft->save();
    $this->home([$draft->id()]);
    $this->drupalLogin($a);
    $this->drupalGet('index.md');
    $this->assertSession()->responseContains('Draft of A');
    $this->drupalLogout();
    $this->drupalLogin($b);
    $this->drupalGet('index.md');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('Draft of A');
  }

  /**
   * A referenced page renamed shows its new name.
   */
  public function testReferencedPageIsNotStale(): void {
    $other = Node::create(['type' => 'page', 'title' => 'Old name', 'field_price' => ['enabled' => 0]]);
    $other->save();
    $this->home([$other->id()]);
    $this->drupalGet('index.md');
    $this->drupalGet('index.md');
    $this->assertSession()->responseContains('Old name');
    $other->setTitle('New name')->save();
    $this->drupalGet('index.md');
    $this->assertSession()->responseContains('New name');
  }

  /**
   * The front page, referencing the given nodes.
   */
  private function home(array $ids): void {
    $home = Node::create([
      'type' => 'page',
      'title' => 'Home',
      'field_ref' => $ids,
      'field_price' => ['enabled' => 0],
    ]);
    $home->save();
    $this->config('system.site')->set('page.front', '/node/' . $home->id())->save();
  }

}
