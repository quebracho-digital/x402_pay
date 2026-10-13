<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Drupal\x402_pay\CircleFacilitator;
use Drupal\x402_pay\Event\PaymentEvent;
use Drupal\x402_pay\FacilitatorException;
use Drupal\x402_pay\PaymentGate;
use Drupal\x402_pay\PaymentHeaders;
use Drupal\x402_pay\PaymentLog;
use Drupal\x402_pay\Signer;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate: charge for a response, and settle only when it is a 2xx.
 *
 * Order: verify the payment, run the handler, settle only on 2xx, and hand
 * the body over only once the money settled.
 */
#[Group('x402_pay')]
final class PaymentGateTest extends UnitTestCase {

  private const PAY_TO = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';
  private const BUYER = '0x857b06519E91e3A54538791bDbb0E22373e36b66';
  private const TX = '0x2189f224626f989efef1c28f8f72a6ea205805f7394313139ddc2f51b027674d';

  /**
   * The facilitator, mocked.
   */
  private CircleFacilitator&MockObject $facilitator;
  /**
   * The payment log, mocked.
   */
  private PaymentLog&MockObject $log;
  /**
   * The gate under test.
   */
  private PaymentGate $gate;
  /**
   * The seller's key.
   */
  private Signer $seller;
  /**
   * The payment events dispatched.
   *
   * @var \Drupal\x402_pay\Event\PaymentEvent[]
   */
  private array $events = [];
  /**
   * How many times the handler ran.
   */
  private int $handlerCalls = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->facilitator = $this->createMock(CircleFacilitator::class);
    $this->log = $this->createMock(PaymentLog::class);
    $dispatcher = new EventDispatcher();
    $dispatcher->addListener(PaymentEvent::class, function (PaymentEvent $event) {
      $this->events[] = $event;
    });
    $this->gate = new PaymentGate($this->facilitator, $this->log, NULL, $dispatcher);
    $this->seller = new Signer('0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80');
  }

  /**
   * The payment requirements of the test product.
   */
  private function requirements(): array {
    return [
      'scheme' => 'exact',
      'network' => 'eip155:5042002',
      'amount' => '10000',
      'asset' => '0x3600000000000000000000000000000000000000',
      'payTo' => self::PAY_TO,
      'maxTimeoutSeconds' => 60,
      'extra' => ['name' => 'USDC', 'version' => '2'],
    ];
  }

  /**
   * The resource the test product describes.
   */
  private function resource(): array {
    return ['url' => 'https://ombu.ddev.site/product/1/call', 'description' => 'Ping', 'mimeType' => 'application/json'];
  }

  /**
   * A payment, with some of the accepted requirements overridden.
   */
  private function payment(array $accepted_overrides = []): array {
    return [
      'x402Version' => 2,
      'resource' => $this->resource(),
      'accepted' => $accepted_overrides + $this->requirements(),
      'payload' => [
        'signature' => '0x' . str_repeat('11', 65),
        'authorization' => [
          'from' => self::BUYER,
          'to' => self::PAY_TO,
          'value' => '10000',
          'validAfter' => '0',
          'validBefore' => '9999999999',
          'nonce' => '0x' . str_repeat('22', 32),
        ],
      ],
    ];
  }

  /**
   * A request carrying this payment, or none.
   */
  private function request(?array $payment): Request {
    $request = Request::create('/product/1/call', 'POST', content: '{}');
    if ($payment !== NULL) {
      $request->headers->set('PAYMENT-SIGNATURE', PaymentHeaders::encode($payment));
    }
    return $request;
  }

  /**
   * Runs the gate around a handler that answers with the given status.
   */
  private function handle(Request $request, int $status = 200): Response {
    return $this->gate->handle($request, $this->requirements(), $this->resource(), $this->seller, function () use ($status) {
      $this->handlerCalls++;
      return new JsonResponse(['pong' => TRUE], $status);
    });
  }

  /**
   * Makes the facilitator verify every payment.
   */
  private function valid(): void {
    $this->facilitator->method('verify')->willReturn(['isValid' => TRUE, 'payer' => self::BUYER]);
  }

  /**
   * Unpaid gets 402 with requirements and never runs the handler.
   */
  public function testUnpaidGets402WithRequirementsAndNeverRunsTheHandler(): void {
    $this->facilitator->expects($this->never())->method('verify');
    $response = $this->handle($this->request(NULL));

    $this->assertSame(402, $response->getStatusCode());
    $required = PaymentHeaders::decode($response->headers->get('PAYMENT-REQUIRED'));
    $this->assertSame(2, $required['x402Version']);
    $this->assertSame($this->resource(), $required['resource']);
    $this->assertSame([$this->requirements()], $required['accepts']);
    $this->assertSame(0, $this->handlerCalls);
  }

  /**
   * Malformed payment is 400.
   */
  public function testMalformedPaymentIs400(): void {
    $request = $this->request(NULL);
    $request->headers->set('PAYMENT-SIGNATURE', '%%%');
    $this->assertSame(400, $this->handle($request)->getStatusCode());
    $this->assertSame(0, $this->handlerCalls);
  }

  /**
   * Payment for other terms is 402 without asking the facilitator.
   */
  #[DataProvider('mismatches')]
  public function testPaymentForOtherTermsIs402WithoutAskingTheFacilitator(array $override): void {
    $this->facilitator->expects($this->never())->method('verify');
    $response = $this->handle($this->request($this->payment($override)));
    $this->assertSame(402, $response->getStatusCode());
    $this->assertSame(0, $this->handlerCalls);
  }

  /**
   * Payments for other terms than those asked.
   */
  public static function mismatches(): array {
    return [
      'less money' => [['amount' => '9999']],
      'other seller' => [['payTo' => '0x0000000000000000000000000000000000000001']],
      'other chain' => [['network' => 'eip155:5042']],
      'other token' => [['asset' => '0x0000000000000000000000000000000000000002']],
      'other scheme' => [['scheme' => 'upto']],
    ];
  }

  /**
   * Address case does not matter.
   */
  public function testAddressCaseDoesNotMatter(): void {
    $this->valid();
    $this->facilitator->method('settle')->willReturn([
      'success' => TRUE,
      'transaction' => self::TX,
      'network' => 'eip155:5042002',
      'payer' => self::BUYER,
    ]);
    $response = $this->handle($this->request($this->payment(['payTo' => strtolower(self::PAY_TO)])));
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Invalid payment is 402 and never runs the handler.
   */
  public function testInvalidPaymentIs402AndNeverRunsTheHandler(): void {
    $this->facilitator->method('verify')->willReturn([
      'isValid' => FALSE,
      'invalidReason' => 'insufficient_funds',
      'payer' => self::BUYER,
    ]);
    $this->facilitator->expects($this->never())->method('settle');
    $response = $this->handle($this->request($this->payment()));

    $this->assertSame(402, $response->getStatusCode());
    $this->assertSame('insufficient_funds', PaymentHeaders::decode($response->headers->get('PAYMENT-REQUIRED'))['error']);
    $this->assertSame(0, $this->handlerCalls);
  }

  /**
   * Paid and delivered is settled and logged.
   */
  public function testPaidAndDeliveredIsSettledAndLogged(): void {
    $this->valid();
    $this->facilitator->expects($this->once())->method('settle')
      ->willReturn(['success' => TRUE, 'transaction' => self::TX, 'network' => 'eip155:5042002', 'payer' => self::BUYER]);
    $this->log->expects($this->once())->method('record')->with($this->callback(fn(array $e) =>
      $e['status'] === 'settled' && $e['transaction'] === self::TX && $e['payer'] === self::BUYER
      && $e['amount'] === '10000' && $e['pay_to'] === self::PAY_TO && $e['handler_status'] === 200
    ));

    $response = $this->handle($this->request($this->payment()));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('{"pong":true}', $response->getContent());
    $settlement = PaymentHeaders::decode($response->headers->get('PAYMENT-RESPONSE'));
    $this->assertTrue($settlement['success']);
    $this->assertSame(self::TX, $settlement['transaction']);
  }

  /**
   * Every outcome in the log is also an event, the same entry.
   */
  public function testEachOutcomeIsAnEvent(): void {
    $this->valid();
    $this->facilitator->method('settle')->willReturn(['success' => TRUE, 'transaction' => self::TX, 'payer' => self::BUYER]);
    $this->handle($this->request($this->payment()));
    $this->handle($this->request($this->payment()), 502);

    $this->assertSame(['settled', 'not_settled'], array_map(fn(PaymentEvent $e) => $e->status(), $this->events));
    $this->assertSame(self::TX, $this->events[0]->entry['transaction']);
    $this->assertSame([], $this->unpaidEvents());
  }

  /**
   * An unpaid request is no outcome: no event.
   */
  private function unpaidEvents(): array {
    $this->events = [];
    $this->handle($this->request(NULL));
    return $this->events;
  }

  /**
   * Reports how long each step took.
   */
  public function testReportsHowLongEachStepTook(): void {
    $this->valid();
    $this->facilitator->method('settle')->willReturn([
      'success' => TRUE,
      'transaction' => self::TX,
      'network' => 'eip155:5042002',
      'payer' => self::BUYER,
    ]);

    $timing = $this->handle($this->request($this->payment()))->headers->get('Server-Timing');

    $this->assertMatchesRegularExpression('/^x402-verify;dur=\d+(\.\d+)?, x402-handler;dur=\d+(\.\d+)?, x402-settle;dur=\d+(\.\d+)?$/', (string) $timing);
  }

  /**
   * Reports the steps that ran when delivery fails.
   */
  public function testReportsTheStepsThatRanWhenDeliveryFails(): void {
    $this->valid();
    $timing = $this->handle($this->request($this->payment()), 502)->headers->get('Server-Timing');
    $this->assertMatchesRegularExpression('/^x402-verify;dur=[\d.]+, x402-handler;dur=[\d.]+$/', (string) $timing);
  }

  /**
   * Failed delivery is never settled.
   */
  public function testFailedDeliveryIsNeverSettled(): void {
    $this->valid();
    $this->facilitator->expects($this->never())->method('settle');
    $this->log->expects($this->once())->method('record')->with($this->callback(fn(array $e) =>
      $e['status'] === 'not_settled' && $e['handler_status'] === 502 && $e['transaction'] === NULL
    ));

    $response = $this->handle($this->request($this->payment()), 502);

    $this->assertSame(502, $response->getStatusCode());
    $this->assertFalse($response->headers->has('PAYMENT-RESPONSE'));
  }

  /**
   * Failed settlement withholds the body.
   */
  public function testFailedSettlementWithholdsTheBody(): void {
    $this->valid();
    $this->facilitator->method('settle')->willReturn([
      'success' => FALSE,
      'errorReason' => 'insufficient_funds',
      'transaction' => '',
      'network' => 'eip155:5042002',
      'payer' => self::BUYER,
    ]);
    $this->log->expects($this->once())->method('record')->with($this->callback(fn(array $e) => $e['status'] === 'failed'));

    $response = $this->handle($this->request($this->payment()));

    $this->assertSame(402, $response->getStatusCode());
    $this->assertStringNotContainsString('pong', $response->getContent());
    $this->assertFalse(PaymentHeaders::decode($response->headers->get('PAYMENT-RESPONSE'))['success']);
  }

  /**
   * Facilitator down before the handler is 502 and nothing runs.
   */
  public function testFacilitatorDownBeforeTheHandlerIs502AndNothingRuns(): void {
    $this->facilitator->method('verify')->willThrowException(new FacilitatorException('timeout'));
    $response = $this->handle($this->request($this->payment()));
    $this->assertSame(502, $response->getStatusCode());
    $this->assertSame(0, $this->handlerCalls);
  }

  /**
   * Facilitator down at settlement withholds the body.
   */
  public function testFacilitatorDownAtSettlementWithholdsTheBody(): void {
    $this->valid();
    $this->facilitator->method('settle')->willThrowException(new FacilitatorException('registration_required'));
    $response = $this->handle($this->request($this->payment()));
    $this->assertSame(502, $response->getStatusCode());
    $this->assertStringNotContainsString('pong', $response->getContent());
  }

}
