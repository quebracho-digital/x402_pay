<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\x402_pay\CircleFacilitator;
use Drupal\x402_pay\PaymentHeaders;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Drupal\x402_pay\Signer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The x402_price field: any entity with it is sold to agents over x402.
 *
 * Agents are charged on the separate route and on the canonical URL;
 * browsers still see the page there.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class PriceFieldTest extends KernelTestBase {

  use UserCreationTrait;

  private const KEY = '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80';
  private const PAY_TO = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';
  private const TX = '0x2189f224626f989efef1c28f8f72a6ea205805f7394313139ddc2f51b027674d';

  protected static $modules = ['x402_pay', 'key', 'entity_test', 'field', 'user', 'system', 'text', 'filter'];

  private EntityTest $entity;
  private FieldConfig $field;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
    $this->installSchema('x402_pay', ['x402_pay_log']);
    $this->installConfig(['system', 'x402_pay']);
    Role::create(['id' => AccountInterface::ANONYMOUS_ROLE, 'label' => 'Anonymous'])
      ->grantPermission('view test entity')
      ->save();

    Key::create([
      'id' => 'seller',
      'label' => 'Seller',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => self::KEY],
    ])->save();
    FieldStorageConfig::create(['field_name' => 'field_price', 'entity_type' => 'entity_test', 'type' => 'x402_price'])->save();
    $this->field = FieldConfig::create([
      'field_name' => 'field_price',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'settings' => [
        'enabled' => TRUE,
        'amount' => '0.01',
        'network' => 'eip155:5042002',
        'key_id' => 'seller',
        'allow_override' => FALSE,
      ],
    ]);
    $this->field->save();

    $this->entity = EntityTest::create(['name' => 'Terms of X', 'field_price' => ['enabled' => 1, 'amount' => '5']]);
    $this->entity->save();
  }

  public function testPayToComesFromTheKeyAndThePriceFromTheField(): void {
    $item = X402PriceItem::find($this->entity);
    $requirements = $item->requirements();
    $this->assertSame(self::PAY_TO, $requirements['payTo']);
    $this->assertSame((new Signer(self::KEY))->address(), $item->seller()->address());
    // Override off: the entity's own amount is ignored.
    $this->assertSame('10000', $requirements['amount']);
  }

  public function testTheEntityPriceWinsWhenOverrideIsAllowed(): void {
    $this->field->setSetting('allow_override', TRUE)->save();
    $entity = EntityTest::load($this->entity->id());
    $this->assertSame('5000000', X402PriceItem::find($entity)->requirements()['amount']);
  }

  public function testAnEntityWithTheBoxUncheckedIsFree(): void {
    $this->entity->set('field_price', ['enabled' => 0])->save();
    $this->assertNull(X402PriceItem::find($this->entity));
  }

  public function testTheFieldTurnedOffMakesEveryEntityFree(): void {
    $this->field->setSetting('enabled', FALSE)->save();
    $this->assertNull(X402PriceItem::find(EntityTest::load($this->entity->id())));
  }

  public function testTheSeparateRouteAsksForPayment(): void {
    $response = $this->get('/x402/entity_test/1');
    $this->assertSame(402, $response->getStatusCode());
    $required = PaymentHeaders::decode($response->headers->get(PaymentHeaders::REQUIRED));
    $this->assertSame(self::PAY_TO, $required['accepts'][0]['payTo']);
    $this->assertSame('10000', $required['accepts'][0]['amount']);
    $this->assertStringEndsWith('/entity_test/1', $required['resource']['url']);
    $this->assertSame('text/markdown', $required['resource']['mimeType']);
  }

  public function testTheSeparateRouteIsNotFoundForAFreeEntity(): void {
    $this->entity->set('field_price', ['enabled' => 0])->save();
    $this->assertSame(404, $this->get('/x402/entity_test/1')->getStatusCode());
  }

  public function testTheCanonicalUrlAsksAgentsForPayment(): void {
    $this->assertSame(402, $this->get('/entity_test/1', ['Accept' => 'text/markdown'])->getStatusCode());
    $this->assertSame(402, $this->get('/entity_test/1', ['Accept' => '*/*'])->getStatusCode());
    $this->assertSame(402, $this->get('/entity_test/1', ['Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.2)'])->getStatusCode());
  }

  public function testABrowserStillSeesThePage(): void {
    $response = $this->get('/entity_test/1', ['Accept' => 'text/html,application/xhtml+xml', 'User-Agent' => 'Mozilla/5.0 Firefox/140.0']);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('</x402/entity_test/1>', (string) $response->headers->get('Link'));
  }

  public function testAPaidRequestGetsMarkdownAndIsLogged(): void {
    $facilitator = $this->createMock(CircleFacilitator::class);
    $facilitator->method('verify')->willReturn(['isValid' => TRUE, 'payer' => '0x857b06519E91e3A54538791bDbb0E22373e36b66']);
    $facilitator->method('settle')->willReturn(['success' => TRUE, 'transaction' => self::TX, 'network' => 'eip155:5042002', 'payer' => '0x857b06519E91e3A54538791bDbb0E22373e36b66']);
    $this->container->set('x402_pay.facilitator', $facilitator);

    $accepted = X402PriceItem::find($this->entity)->requirements();
    $header = PaymentHeaders::encode(['x402Version' => 2, 'accepted' => $accepted, 'payload' => []]);
    $response = $this->get('/entity_test/1', [PaymentHeaders::SIGNATURE => $header]);

    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertStringStartsWith('text/markdown', $response->headers->get('Content-Type'));
    $this->assertStringStartsWith("# Terms of X\n", $response->getContent());
    $this->assertSame(self::TX, PaymentHeaders::decode($response->headers->get(PaymentHeaders::RESPONSE))['transaction']);
    $row = \Drupal::database()->select('x402_pay_log', 'l')->fields('l')->execute()->fetchAssoc();
    $this->assertSame('settled', $row['status']);
    $this->assertSame(self::PAY_TO, $row['pay_to']);
  }

  public function testOnlyThoseAllowedCanSetPrices(): void {
    $items = $this->entity->get('field_price');
    $this->assertFalse($items->access('edit', $this->setUpCurrentUser([], ['administer entity_test content'])));
    $this->assertTrue($items->access('edit', $this->setUpCurrentUser([], ['administer entity_test content', 'set x402 prices'])));
  }

  private function get(string $path, array $headers = []): Response {
    $request = Request::create($path);
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    return $this->container->get('http_kernel')->handle($request);
  }

}
