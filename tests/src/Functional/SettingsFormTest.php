<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Functional;

use Drupal\key\Entity\Key;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The settings form: facilitator access and the sweep.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class SettingsFormTest extends BrowserTestBase {

  private const COLD = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';

  /**
   * Anvil's first key's address: the seller swept.
   */
  private const HOT = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['x402_pay', 'key'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $keys = [
      'seller' => '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80',
      'circle' => 'TEST_API_KEY:abc:def',
    ];
    foreach ($keys as $id => $value) {
      Key::create([
        'id' => $id,
        'label' => ucfirst($id),
        'key_type' => 'authentication',
        'key_provider' => 'config',
        'key_provider_settings' => ['key_value' => $value],
      ])->save();
    }
    $this->drupalLogin($this->drupalCreateUser(['administer x402 pay']));
  }

  /**
   * Refuses what would leave money on the site; saves the rest.
   */
  public function testValidatesAndSaves(): void {
    $this->drupalGet('admin/config/services/x402');
    $this->assertSession()->pageTextContains('Seller — ' . self::HOT);
    // The API key option is not a wallet: it cannot be swept.
    $this->assertSession()->fieldNotExists('sweep[keys][circle]');

    $this->submitForm(['sweep[to]' => self::HOT, 'sweep[keys][seller]' => TRUE], 'Save configuration');
    $this->assertSession()->pageTextContains('The cold wallet cannot be one of the wallets swept');

    $this->submitForm(['facilitator[auth]' => 'api_key', 'sweep[to]' => ''], 'Save configuration');
    $this->assertSession()->pageTextContains('Choose a key that holds the Circle API key.');

    $this->submitForm(['facilitator[own_networks][eip155:5042]' => TRUE, 'sweep[to]' => ''], 'Save configuration');
    $this->assertSession()->pageTextContains('Settling on chain needs a gas wallet.');

    // Solana is not ours to settle; a network ours settles takes no other.
    $this->assertSession()->fieldNotExists('facilitator[own_networks][solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1]');
    $this->submitForm([
      'facilitator[own_networks][eip155:5042]' => TRUE,
      'facilitator[own_key_id]' => 'seller',
      'facilitator[remote][eip155:5042][url]' => 'https://facilitator.example',
      'sweep[to]' => '',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Arc (USDC) is settled by this site: leave its other facilitator empty.');

    $this->submitForm([
      'facilitator[auth]' => 'api_key',
      'facilitator[api_key_id]' => 'circle',
      'facilitator[own_networks][eip155:5042]' => TRUE,
      'facilitator[own_key_id]' => 'seller',
      'facilitator[remote][eip155:5042][url]' => '',
      'facilitator[remote][solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1][url]' => 'https://x402.org/facilitator',
      'sweep[enabled]' => TRUE,
      'sweep[to]' => strtolower(self::COLD),
      'sweep[keys][seller]' => TRUE,
      'sweep[min]' => '0.5',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $config = $this->config('x402_pay.settings');
    $this->assertSame([
      'auth' => 'api_key',
      'api_key_id' => 'circle',
      'own_networks' => ['eip155:5042'],
      'own_key_id' => 'seller',
      'remote' => [
        'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1' => ['url' => 'https://x402.org/facilitator', 'key_id' => ''],
      ],
    ], $config->get('facilitator'));
    $this->assertSame(['enabled' => TRUE, 'to' => self::COLD, 'keys' => ['seller'], 'min' => '0.5', 'interval' => 21600], $config->get('sweep'));
  }

}
