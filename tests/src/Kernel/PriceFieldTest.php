<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Kernel;

use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Session\AccountInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestMul;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\x402_pay\CircleFacilitator;
use Drupal\x402_pay\Event\DeliveryEvent;
use Drupal\x402_pay\Event\PriceEvent;
use Drupal\x402_pay\PaymentHeaders;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Drupal\x402_pay\Signer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The x402_price field: any entity with it is sold to agents over x402.
 *
 * Each field sells one view mode at one price. At most one of them charges
 * on the entity's own URL, where browsers still see the page; every one is
 * sold at /x402/{entity_type}/{id}/{view_mode}.
 */
#[Group('x402_pay')]
#[RunTestsInSeparateProcesses]
final class PriceFieldTest extends KernelTestBase {

  use UserCreationTrait;

  private const KEY = '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80';
  private const PAY_TO = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';
  private const TX = '0x2189f224626f989efef1c28f8f72a6ea205805f7394313139ddc2f51b027674d';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['x402_pay', 'key', 'entity_test', 'field', 'user', 'system', 'text', 'filter'];

  /**
   * The entity sold.
   */
  private EntityTest $entity;
  /**
   * The price field.
   */
  private FieldConfig $field;

  /**
   * {@inheritdoc}
   */
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

    $this->field = $this->addField('entity_test', 'field_price', [
      'amount' => '0.01',
      'view_mode' => 'full',
      'canonical' => TRUE,
    ]);
    // The price itself is never part of what is sold.
    EntityViewMode::create(['id' => 'entity_test.full', 'label' => 'Full', 'targetEntityType' => 'entity_test'])->save();
    \Drupal::service('entity_display.repository')->getViewDisplay('entity_test', 'entity_test', 'full')
      ->setComponent('field_price', ['type' => 'x402_price_default'])
      ->save();
    $this->entity = EntityTest::create(['name' => 'Terms of X', 'field_price' => ['enabled' => 1, 'amount' => '5']]);
    $this->entity->save();
  }

  /**
   * Pay to comes from the key and the price from the field.
   */
  public function testPayToComesFromTheKeyAndThePriceFromTheField(): void {
    $item = X402PriceItem::find($this->entity);
    $requirements = $item->requirements();
    $this->assertSame(self::PAY_TO, $requirements['payTo']);
    $this->assertSame((new Signer(self::KEY))->address(), $item->seller()->address());
    // Override off: the entity's own amount is ignored.
    $this->assertSame('10000', $requirements['amount']);
  }

  /**
   * The entity price wins when override is allowed.
   */
  public function testTheEntityPriceWinsWhenOverrideIsAllowed(): void {
    $this->field->setSetting('allow_override', TRUE)->save();
    $entity = EntityTest::load($this->entity->id());
    $this->assertSame('5000000', X402PriceItem::find($entity)->requirements()['amount']);
  }

  /**
   * An entity with the box unchecked is free.
   */
  public function testAnEntityWithTheBoxUncheckedIsFree(): void {
    $this->entity->set('field_price', ['enabled' => 0])->save();
    $this->assertNull(X402PriceItem::find($this->entity));
  }

  /**
   * The field turned off makes every entity free.
   */
  public function testTheFieldTurnedOffMakesEveryEntityFree(): void {
    $this->field->setSetting('enabled', FALSE)->save();
    $this->assertNull(X402PriceItem::find(EntityTest::load($this->entity->id())));
  }

  /**
   * The separate route asks for payment.
   */
  public function testTheSeparateRouteAsksForPayment(): void {
    $response = $this->get('/x402/entity_test/1');
    $this->assertSame(402, $response->getStatusCode());
    $required = PaymentHeaders::decode($response->headers->get(PaymentHeaders::REQUIRED));
    $this->assertSame(self::PAY_TO, $required['accepts'][0]['payTo']);
    $this->assertSame('10000', $required['accepts'][0]['amount']);
    $this->assertStringEndsWith('/entity_test/1', $required['resource']['url']);
    $this->assertSame('text/markdown', $required['resource']['mimeType']);
  }

  /**
   * The separate route is not found for free entities.
   */
  public function testTheSeparateRouteIsNotFoundForFreeEntities(): void {
    $this->entity->set('field_price', ['enabled' => 0])->save();
    $this->assertSame(404, $this->get('/x402/entity_test/1')->getStatusCode());
  }

  /**
   * The canonical url asks agents for payment.
   */
  public function testTheCanonicalUrlAsksAgentsForPayment(): void {
    $this->assertSame(402, $this->get('/entity_test/1', ['Accept' => 'text/markdown'])->getStatusCode());
    $this->assertSame(402, $this->get('/entity_test/1', ['Accept' => '*/*'])->getStatusCode());
    $this->assertSame(402, $this->get('/entity_test/1', [
      'Accept' => 'text/html',
      'User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.2)',
    ])->getStatusCode());
  }

  /**
   * Browsers still see the page.
   */
  public function testBrowsersStillSeeThePage(): void {
    $response = $this->get('/entity_test/1', [
      'Accept' => 'text/html,application/xhtml+xml',
      'User-Agent' => 'Mozilla/5.0 Firefox/140.0',
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('</x402/entity_test/1/full>', (string) $response->headers->get('Link'));
  }

  /**
   * Paid requests get markdown and are logged.
   */
  public function testPaidRequestsGetMarkdownAndAreLogged(): void {
    $this->mockFacilitator();
    $accepted = X402PriceItem::find($this->entity)->requirements();
    $response = $this->get('/entity_test/1', [PaymentHeaders::SIGNATURE => $this->payment($accepted)]);

    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertStringStartsWith('text/markdown', $response->headers->get('Content-Type'));
    $this->assertStringStartsWith("# Terms of X\n", $response->getContent());
    $this->assertStringNotContainsString('USD 0.01', $response->getContent(), 'The price is not part of what is sold.');
    $this->assertSame(self::TX, PaymentHeaders::decode($response->headers->get(PaymentHeaders::RESPONSE))['transaction']);
    $row = \Drupal::database()->select('x402_pay_log', 'l')->fields('l')->execute()->fetchAssoc();
    $this->assertSame('settled', $row['status']);
    $this->assertSame(self::PAY_TO, $row['pay_to']);
  }

  /**
   * What is sold is what the site builder chose.
   */
  public function testWhatIsSoldIsWhatTheSiteBuilderChose(): void {
    // Fields a display cannot configure (here the name, like a node's title,
    // author and date) belong to the entity's template, not to what is sold.
    $markdown = \Drupal::service('x402_pay.delivery')->markdown($this->entity, 'full')->getContent();
    $this->assertSame(1, substr_count($markdown, 'Terms of X'), 'The label once, as the title.');
  }

  /**
   * Only those allowed can set prices.
   */
  public function testOnlyThoseAllowedCanSetPrices(): void {
    $items = $this->entity->get('field_price');
    $this->assertFalse($items->access('edit', $this->setUpCurrentUser([], ['administer entity_test content'])));
    $this->assertTrue($items->access('edit', $this->setUpCurrentUser([], [
      'administer entity_test content',
      'set x402 prices',
    ])));
  }

  /**
   * Each field sells its own view mode.
   */
  public function testEachFieldSellsItsOwnViewMode(): void {
    $this->addField('entity_test', 'field_summary', ['amount' => '0.002', 'view_mode' => 'teaser', 'canonical' => FALSE]);
    $entity = EntityTest::load($this->entity->id());
    $entity->set('field_summary', ['enabled' => 1])->save();

    $this->assertSame('2000', $this->required('/x402/entity_test/1/teaser')['accepts'][0]['amount']);
    $this->assertStringEndsWith('/x402/entity_test/1/teaser', $this->required('/x402/entity_test/1/teaser')['resource']['url']);
    $this->assertSame('10000', $this->required('/x402/entity_test/1/full')['accepts'][0]['amount']);
    // Without a view mode: the product sold on the canonical URL.
    $this->assertSame('10000', $this->required('/x402/entity_test/1')['accepts'][0]['amount']);
    $this->assertSame('10000', $this->required('/entity_test/1', ['Accept' => '*/*'])['accepts'][0]['amount']);
    $this->assertSame(404, $this->get('/x402/entity_test/1/rss')->getStatusCode());

    $link = implode(', ', $this->get('/entity_test/1', ['Accept' => 'text/html'])->headers->all('Link'));
    $this->assertStringContainsString('</x402/entity_test/1/full>', $link);
    $this->assertStringContainsString('</x402/entity_test/1/teaser>', $link);
  }

  /**
   * Two fields cannot sell the same view mode.
   */
  public function testTwoFieldsCannotSellTheSameViewMode(): void {
    $this->expectException(\LogicException::class);
    $this->addField('entity_test', 'field_summary', ['view_mode' => 'full', 'canonical' => FALSE]);
  }

  /**
   * Only one field charges on the canonical url.
   */
  public function testOnlyOneFieldChargesOnTheCanonicalUrl(): void {
    $this->expectException(\LogicException::class);
    $this->addField('entity_test', 'field_summary', ['view_mode' => 'teaser', 'canonical' => TRUE]);
  }

  /**
   * Without canonical field the canonical url is free.
   */
  public function testWithoutCanonicalFieldTheCanonicalUrlIsFree(): void {
    $this->field->setSetting('canonical', FALSE)->save();
    $this->assertSame(200, $this->get('/entity_test/1', ['Accept' => '*/*'])->getStatusCode());
    $this->assertSame(404, $this->get('/x402/entity_test/1')->getStatusCode());
    $this->assertSame(402, $this->get('/x402/entity_test/1/full')->getStatusCode());
  }

  /**
   * The price event can change the price.
   */
  public function testThePriceEventCanChangeThePrice(): void {
    $this->listen(fn(PriceEvent $e) => $e->setPrice('0.5'));
    $this->assertSame('500000', $this->required('/x402/entity_test/1')['accepts'][0]['amount']);
  }

  /**
   * The price event can make it free.
   */
  public function testThePriceEventCanMakeItFree(): void {
    $this->listen(fn(PriceEvent $e) => $e->makeFree());
    $response = $this->get('/x402/entity_test/1');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith("# Terms of X\n", $response->getContent());
    $this->assertFalse(\Drupal::database()->select('x402_pay_log', 'l')->countQuery()->execute()->fetchField() > 0, 'Nothing is charged, nothing is logged.');
  }

  /**
   * The price event can deny.
   */
  public function testThePriceEventCanDeny(): void {
    $this->listen(fn(PriceEvent $e) => $e->deny());
    $this->assertSame(403, $this->get('/x402/entity_test/1')->getStatusCode());
    $this->assertSame(403, $this->get('/entity_test/1', ['Accept' => '*/*'])->getStatusCode());
  }

  /**
   * The price event knows the context.
   */
  public function testThePriceEventKnowsTheContext(): void {
    $seen = NULL;
    $this->listen(function (PriceEvent $e) use (&$seen) {
      $seen = [$e->entity->id(), $e->viewMode, $e->price(), $e->account->isAnonymous()];
    });
    $this->get('/x402/entity_test/1/full');
    $this->assertSame(['1', 'full', '0.01', TRUE], $seen);
  }

  /**
   * The price event can choose the seller and describe the product.
   */
  public function testThePriceEventCanChooseTheSellerAndDescribeTheProduct(): void {
    // Hardhat's second test account: one seller per listing, not per field.
    $other = new Signer('0x59c6995e998f97a5a0044966f0945389dc9e86dae88c7a8412f4603b6b78690d');
    $this->listen(function (PriceEvent $e) use ($other) {
      $e->setSeller($other);
      $e->setMimeType('application/json');
    });
    $required = $this->required('/x402/entity_test/1');
    $this->assertSame($other->address(), $required['accepts'][0]['payTo']);
    $this->assertSame('application/json', $required['resource']['mimeType']);
  }

  /**
   * A seller can be an address alone: no key on the site signs for it.
   *
   * Announced in the 402, and the facilitator gets no key: with a Circle
   * API key it needs none.
   */
  public function testTheSellerCanBeAnAddressAlone(): void {
    $cold = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
    $facilitator = $this->createMock(CircleFacilitator::class);
    $facilitator->expects($this->once())->method('verify')
      ->with($this->anything(), $this->callback(fn($r) => $r['payTo'] === $cold), NULL)
      ->willReturn(['isValid' => TRUE, 'payer' => '0x857b06519E91e3A54538791bDbb0E22373e36b66']);
    $settled = ['success' => TRUE, 'transaction' => self::TX, 'network' => 'eip155:5042002'];
    $facilitator->method('settle')->willReturn($settled);
    $this->container->set('x402_pay.facilitator', $facilitator);
    $this->listen(fn(PriceEvent $e) => $e->setPayTo(strtolower($cold)));

    $required = $this->required('/x402/entity_test/1');
    $this->assertSame($cold, $required['accepts'][0]['payTo']);
    $response = $this->get('/x402/entity_test/1', [PaymentHeaders::SIGNATURE => $this->payment($required['accepts'][0])]);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
  }

  /**
   * A field without a key sells to its address.
   */
  public function testFieldWithoutKeySellsToItsAddress(): void {
    FieldConfig::loadByName('entity_test', 'entity_test', 'field_price')
      ->setSetting('key_id', '')
      ->setSetting('pay_to', '0x70997970c51812dc3a010c7d01b50e0d17dc79c8')
      ->save();
    $this->resetFieldCaches();
    $item = X402PriceItem::find(EntityTest::load(1));
    $this->assertNull($item->seller());
    $this->assertSame('0x70997970C51812dc3A010C7d01b50e0d17dc79C8', $item->requirements()['payTo']);
  }

  /**
   * On Solana a field sells to a base58 address, case kept, in USDC's mint.
   */
  public function testSolanaFieldSellsToItsAddress(): void {
    FieldConfig::loadByName('entity_test', 'entity_test', 'field_price')
      // cspell:disable-next-line
      ->setSetting('network', 'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1')
      ->setSetting('key_id', '')
      // cspell:disable-next-line
      ->setSetting('pay_to', '2wKupLR9q6wXYppw8Gr2NvWxKBUqm4PPJKkQfoxHDBg4')
      ->save();
    $this->resetFieldCaches();
    $requirements = X402PriceItem::find(EntityTest::load(1))->requirements();
    // cspell:disable-next-line
    $this->assertSame('2wKupLR9q6wXYppw8Gr2NvWxKBUqm4PPJKkQfoxHDBg4', $requirements['payTo']);
    // cspell:disable-next-line
    $this->assertSame('4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU', $requirements['asset']);

    // The price event keeps a Solana seller's case too.
    // cspell:disable-next-line
    $this->listen(fn(PriceEvent $e) => $e->setPayTo('9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM'));
    // cspell:disable-next-line
    $this->assertSame('9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM', $this->discovery()['items'][0]['accepts'][0]['payTo']);
  }

  /**
   * The price event sells one entity on another network than the field's.
   */
  public function testThePriceEventChoosesTheNetwork(): void {
    $refused = NULL;
    $this->listen(function (PriceEvent $e) use (&$refused) {
      try {
        $e->setNetwork('solana:nowhere');
      }
      catch (\InvalidArgumentException $exception) {
        $refused = $exception->getMessage();
      }
      // cspell:disable-next-line
      $e->setNetwork('solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1');
      // cspell:disable-next-line
      $e->setPayTo('9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM');
    });
    $accepts = $this->discovery()['items'][0]['accepts'][0];
    // cspell:disable
    $this->assertSame([
      'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1',
      '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU',
      '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM',
    ], [$accepts['network'], $accepts['asset'], $accepts['payTo']]);
    // cspell:enable
    $this->assertSame('Unknown network solana:nowhere.', $refused);
  }

  /**
   * Slow products give the buyer more time to pay.
   */
  public function testSlowProductsGiveTheBuyerMoreTimeToPay(): void {
    // The buyer's signature must outlive the delivery, or the settlement
    // fails after the seller did the work.
    $this->listen(fn(PriceEvent $e) => $e->setTimeout(600));
    $this->assertSame(600, $this->required('/x402/entity_test/1')['accepts'][0]['maxTimeoutSeconds']);
    $this->assertSame(600, $this->discovery()['items'][0]['accepts'][0]['maxTimeoutSeconds']);
  }

  /**
   * The delivery event replaces what is delivered.
   */
  public function testTheDeliveryEventReplacesWhatIsDelivered(): void {
    $this->mockFacilitator();
    $this->container->get('event_dispatcher')->addListener(DeliveryEvent::class, function (DeliveryEvent $e) {
      $input = json_decode($e->request->getContent(), TRUE);
      $e->setResponse(new JsonResponse(['echo' => $input['q'], 'of' => $e->entity->label(), 'mode' => $e->viewMode]));
    });
    $request = Request::create('/x402/entity_test/1', 'POST', content: '{"q":"hi"}');
    $request->headers->set(PaymentHeaders::SIGNATURE, $this->payment(X402PriceItem::find($this->entity)->requirements()));
    $response = $this->container->get('http_kernel')->handle($request);

    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertSame(['echo' => 'hi', 'of' => 'Terms of X', 'mode' => 'full'], json_decode($response->getContent(), TRUE));
    $this->assertTrue($response->headers->has(PaymentHeaders::RESPONSE));
  }

  /**
   * Failed deliveries are not charged.
   */
  public function testFailedDeliveriesAreNotCharged(): void {
    $facilitator = $this->createMock(CircleFacilitator::class);
    $facilitator->method('verify')->willReturn([
      'isValid' => TRUE,
      'payer' => '0x857b06519E91e3A54538791bDbb0E22373e36b66',
    ]);
    $facilitator->expects($this->never())->method('settle');
    $this->container->set('x402_pay.facilitator', $facilitator);
    $this->container->get('event_dispatcher')->addListener(DeliveryEvent::class, fn(DeliveryEvent $e) => $e->setResponse(new JsonResponse(['error' => 'seller down'], 502)));

    $response = $this->get('/x402/entity_test/1', [PaymentHeaders::SIGNATURE => $this->payment(X402PriceItem::find($this->entity)->requirements())]);
    $this->assertSame(502, $response->getStatusCode());
    $this->assertSame('not_settled', \Drupal::database()->select('x402_pay_log', 'l')->fields('l', ['status'])->execute()->fetchField());
  }

  /**
   * Those with the permission read free.
   */
  public function testThoseWithThePermissionReadFree(): void {
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('access x402 content free')->save();
    $response = $this->get('/x402/entity_test/1');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith("# Terms of X\n", $response->getContent());
  }

  /**
   * Each translation has its own price.
   */
  public function testEachTranslationHasItsOwnPrice(): void {
    $this->enableModules(['language']);
    $this->installEntitySchema('entity_test_mul');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    // The /es prefix is handled only once the container knows the site is
    // multilingual.
    $this->container->get('kernel')->rebuildContainer();
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('view test entity translations')->save();
    $this->addField('entity_test_mul', 'field_price', ['amount' => '0.01', 'allow_override' => TRUE], TRUE);

    $entity = EntityTestMul::create(['name' => 'Terms of X', 'field_price' => ['enabled' => 1, 'amount' => '0.02']]);
    $entity->addTranslation('es', ['name' => 'Términos de X', 'field_price' => ['enabled' => 1, 'amount' => '0.05']]);
    $entity->save();
    \Drupal::service('router.builder')->rebuild();

    $this->assertSame('20000', X402PriceItem::find($entity)->requirements()['amount']);
    $this->assertSame('50000', X402PriceItem::find($entity->getTranslation('es'))->requirements()['amount']);
    $this->assertSame('20000', $this->required('/entity_test_mul/manage/1', ['Accept' => '*/*'])['accepts'][0]['amount']);
    $this->assertSame('50000', $this->required('/es/entity_test_mul/manage/1', ['Accept' => '*/*'])['accepts'][0]['amount']);
  }

  /**
   * The update keeps older fields charging on the canonical url.
   */
  public function testTheUpdateKeepsOlderFieldsChargingOnTheCanonicalUrl(): void {
    // A field saved before view_mode and canonical existed: Drupal does not
    // fill new settings into stored fields, so it sold nothing.
    $config = \Drupal::configFactory()->getEditable('field.field.entity_test.entity_test.field_price');
    $config->clear('settings.view_mode')->clear('settings.canonical')->save();
    $this->resetFieldCaches();
    $this->assertSame(200, $this->get('/entity_test/1', ['Accept' => '*/*'])->getStatusCode());

    \Drupal::moduleHandler()->loadInclude('x402_pay', 'install');
    x402_pay_update_10002();
    $this->resetFieldCaches();
    $this->assertSame('full', \Drupal::config('field.field.entity_test.entity_test.field_price')->get('settings.view_mode'));
    $this->assertSame(402, $this->get('/entity_test/1', ['Accept' => '*/*'])->getStatusCode());
  }

  /**
   * The discovery lists every product like circles.
   */
  public function testTheDiscoveryListsEveryProductLikeCircles(): void {
    $this->addField('entity_test', 'field_summary', ['amount' => '0.002', 'view_mode' => 'teaser', 'canonical' => FALSE]);
    EntityTest::load(1)->set('field_summary', ['enabled' => 1])->save();
    EntityTest::create(['name' => 'Free one', 'field_price' => ['enabled' => 0]])->save();

    $discovery = $this->discovery();
    $this->assertSame(2, $discovery['x402Version']);
    $this->assertSame(2, $discovery['pagination']['total'], 'Two products of one entity; the free entity sells nothing.');
    $byResource = array_column($discovery['items'], NULL, 'resource');
    $canonical = $byResource[array_key_first(array_filter($byResource, fn($r) => str_ends_with($r, '/entity_test/1'), ARRAY_FILTER_USE_KEY))];
    $this->assertSame('http', $canonical['type']);
    $this->assertSame('10000', $canonical['accepts'][0]['amount']);
    $this->assertSame(self::PAY_TO, $canonical['accepts'][0]['payTo']);
    $this->assertSame('Terms of X', $canonical['metadata']['description']);
    $this->assertSame('GET', $canonical['metadata']['method']);
    $this->assertSame('text/markdown', $canonical['metadata']['mimeType']);
    $summary = array_values(array_filter($discovery['items'], fn($i) => str_ends_with($i['resource'], '/x402/entity_test/1/teaser')));
    $this->assertSame('2000', $summary[0]['accepts'][0]['amount']);
  }

  /**
   * The discovery quotes like the sale.
   */
  public function testTheDiscoveryQuotesLikeTheSale(): void {
    // The catalogue must announce what the sale will charge, and to whom.
    $other = new Signer('0x59c6995e998f97a5a0044966f0945389dc9e86dae88c7a8412f4603b6b78690d');
    $this->listen(function (PriceEvent $e) use ($other) {
      $e->setSeller($other);
      $e->setPrice('0.03');
      $e->setMimeType('application/json');
    });
    $item = $this->discovery()['items'][0];
    $this->assertSame($other->address(), $item['accepts'][0]['payTo']);
    $this->assertSame('30000', $item['accepts'][0]['amount']);
    $this->assertSame('application/json', $item['metadata']['mimeType']);
  }

  /**
   * The discovery leaves out what is denied.
   */
  public function testTheDiscoveryLeavesOutWhatIsDenied(): void {
    $this->listen(fn(PriceEvent $e) => $e->deny());
    $this->assertSame(0, $this->discovery()['pagination']['total']);
  }

  /**
   * The discovery leaves out what cannot be sold.
   */
  public function testTheDiscoveryLeavesOutWhatCannotBeSold(): void {
    // A product without a usable seller key cannot be paid for: listing it
    // would only send agents to a 503; failing the whole catalogue is worse.
    $this->field->setSetting('key_id', 'missing')->save();
    $this->assertSame(0, $this->discovery()['pagination']['total']);
  }

  /**
   * The discovery paginates.
   */
  public function testTheDiscoveryPaginates(): void {
    EntityTest::create(['name' => 'Second', 'field_price' => ['enabled' => 1]])->save();
    $page = $this->discovery('?limit=1&offset=1');
    $this->assertCount(1, $page['items']);
    $this->assertSame(['limit' => 1, 'offset' => 1, 'total' => 2], $page['pagination']);
  }

  /**
   * The discovery lists nothing that cannot be seen.
   */
  public function testTheDiscoveryListsNothingThatCannotBeSeen(): void {
    Role::load(AccountInterface::ANONYMOUS_ROLE)->revokePermission('view test entity')->save();
    $this->assertSame([], $this->discovery()['items']);
  }

  /**
   * The discovery forgets fields turned off.
   */
  public function testTheDiscoveryForgetsFieldsTurnedOff(): void {
    $this->field->setSetting('enabled', FALSE)->save();
    $this->assertSame(0, $this->discovery()['pagination']['total']);
  }

  /**
   * GET /x402/discovery, decoded.
   */
  private function discovery(string $query = ''): array {
    $response = $this->get('/x402/discovery' . $query);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
    return json_decode($response->getContent(), TRUE);
  }

  /**
   * Adds an x402_price field with the test seller key.
   */
  private function addField(string $entityType, string $name, array $settings, bool $translatable = FALSE): FieldConfig {
    if (!FieldStorageConfig::loadByName($entityType, $name)) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => $entityType, 'type' => 'x402_price'])->save();
    }
    $field = FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'bundle' => $entityType,
      'translatable' => $translatable,
      'settings' => $settings + ['key_id' => 'seller'],
    ]);
    $field->save();
    return $field;
  }

  /**
   * Forgets cached field definitions and entities.
   */
  private function resetFieldCaches(): void {
    \Drupal::entityTypeManager()->getStorage('field_config')->resetCache();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    \Drupal::entityTypeManager()->getStorage('entity_test')->resetCache();
  }

  /**
   * A facilitator that verifies and settles every payment.
   */
  private function mockFacilitator(): void {
    $facilitator = $this->createMock(CircleFacilitator::class);
    $facilitator->method('verify')->willReturn([
      'isValid' => TRUE,
      'payer' => '0x857b06519E91e3A54538791bDbb0E22373e36b66',
    ]);
    $facilitator->method('settle')->willReturn([
      'success' => TRUE,
      'transaction' => self::TX,
      'network' => 'eip155:5042002',
      'payer' => '0x857b06519E91e3A54538791bDbb0E22373e36b66',
    ]);
    $this->container->set('x402_pay.facilitator', $facilitator);
  }

  /**
   * A PAYMENT-SIGNATURE header for the accepted requirements.
   */
  private function payment(array $accepted): string {
    return PaymentHeaders::encode(['x402Version' => 2, 'accepted' => $accepted, 'payload' => []]);
  }

  /**
   * Adds a price event listener.
   */
  private function listen(callable $listener): void {
    $this->container->get('event_dispatcher')->addListener(PriceEvent::class, $listener);
  }

  /**
   * The decoded PAYMENT-REQUIRED of a 402.
   */
  private function required(string $path, array $headers = []): array {
    $response = $this->get($path, $headers);
    $this->assertSame(402, $response->getStatusCode(), $response->getContent());
    return PaymentHeaders::decode($response->headers->get(PaymentHeaders::REQUIRED));
  }

  /**
   * Handles a GET request through the kernel.
   */
  private function get(string $path, array $headers = []): Response {
    $request = Request::create($path);
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    return $this->container->get('http_kernel')->handle($request);
  }

}
