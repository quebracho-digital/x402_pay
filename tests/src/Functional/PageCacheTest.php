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
 * The page cache never hands a paid entity's page to an agent.
 *
 * A functional test because the page cache ignores command-line requests,
 * so a kernel test cannot reach it.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class PageCacheTest extends BrowserTestBase {

  protected static $modules = ['x402_pay', 'key', 'entity_test', 'page_cache', 'dynamic_page_cache'];

  protected $defaultTheme = 'stark';

  public function testRepeatedBrowserVisitsDoNotCacheThePageForAgents(): void {
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('view test entity')->save();
    Key::create([
      'id' => 'seller',
      'label' => 'Seller',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80'],
    ])->save();
    FieldStorageConfig::create(['field_name' => 'field_price', 'entity_type' => 'entity_test', 'type' => 'x402_price'])->save();
    FieldConfig::create(['field_name' => 'field_price', 'entity_type' => 'entity_test', 'bundle' => 'entity_test', 'settings' => ['key_id' => 'seller']])->save();
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

}
