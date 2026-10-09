<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Charges for a response over x402, and settles only when it is a 2xx.
 *
 * verify → run the handler → 2xx? settle → hand over the body only once the
 * money settled. A failed handler costs the buyer nothing; a failed
 * settlement gets the buyer nothing.
 */
class PaymentGate {

  public function __construct(
    private readonly CircleFacilitator $facilitator,
    private readonly PaymentLog $log,
  ) {}

  /**
   * @param array $requirements
   *   One x402 PaymentRequirements (scheme, network, amount, asset, payTo,
   *   maxTimeoutSeconds, extra). Set by the caller, so price and payTo can
   *   differ per listing.
   * @param array $resource
   *   url, description, mimeType.
   * @param \Drupal\x402_pay\Signer $seller
   *   The payTo key, for the facilitator's seller proof.
   * @param callable $handler
   *   Produces the paid response. Runs only after the payment verified.
   */
  public function handle(Request $request, array $requirements, array $resource, Signer $seller, callable $handler): Response {
    $header = $request->headers->get(PaymentHeaders::SIGNATURE);
    if ($header === NULL) {
      return $this->required($requirements, $resource, 'PAYMENT-SIGNATURE header is required');
    }
    try {
      $payment = PaymentHeaders::decode($header);
    }
    catch (InvalidPaymentException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 400);
    }
    if (!$this->matches($payment['accepted'] ?? [], $requirements)) {
      return $this->required($requirements, $resource, 'The payment does not match the requirements');
    }

    try {
      $verified = $this->facilitator->verify($payment, $requirements, $seller);
    }
    catch (FacilitatorException $e) {
      return new JsonResponse(['error' => 'Payment facilitator unavailable: ' . $e->getMessage()], 502);
    }
    if (empty($verified['isValid'])) {
      return $this->required($requirements, $resource, $verified['invalidReason'] ?? 'invalid_payment');
    }

    $response = $handler();
    $entry = [
      'resource' => $resource['url'],
      'network' => $requirements['network'],
      'amount' => $requirements['amount'],
      'pay_to' => $requirements['payTo'],
      'payer' => $verified['payer'] ?? NULL,
      'handler_status' => $response->getStatusCode(),
      'transaction' => NULL,
    ];
    if (!$response->isSuccessful()) {
      $this->log->record(['status' => 'not_settled'] + $entry);
      return $response;
    }

    try {
      $settlement = $this->facilitator->settle($payment, $requirements, $seller);
    }
    catch (FacilitatorException $e) {
      $this->log->record(['status' => 'failed', 'error' => $e->getMessage()] + $entry);
      return new JsonResponse(['error' => 'Payment facilitator unavailable: ' . $e->getMessage()], 502);
    }
    if (empty($settlement['success'])) {
      $this->log->record(['status' => 'failed', 'error' => $settlement['errorReason'] ?? NULL] + $entry);
      $failed = new JsonResponse(['error' => $settlement['errorReason'] ?? 'settlement_failed'], 402);
      $failed->headers->set(PaymentHeaders::RESPONSE, PaymentHeaders::encode($settlement));
      return $failed;
    }

    $this->log->record(['status' => 'settled', 'transaction' => $settlement['transaction'], 'payer' => $settlement['payer'] ?? $entry['payer']] + $entry);
    $response->headers->set(PaymentHeaders::RESPONSE, PaymentHeaders::encode([
      'success' => TRUE,
      'transaction' => $settlement['transaction'],
      'network' => $settlement['network'] ?? $requirements['network'],
      'payer' => $settlement['payer'] ?? $entry['payer'],
    ]));
    return $response;
  }

  private function required(array $requirements, array $resource, string $error): Response {
    $required = ['x402Version' => 2, 'error' => $error, 'resource' => $resource, 'accepts' => [$requirements]];
    $response = new JsonResponse($required, 402);
    $response->headers->set(PaymentHeaders::REQUIRED, PaymentHeaders::encode($required));
    return $response;
  }

  /**
   * The buyer must pay exactly what we ask: same scheme, chain, token, seller
   * and amount. Addresses compare case-insensitively.
   */
  private function matches(array $accepted, array $requirements): bool {
    foreach (['scheme', 'network', 'amount'] as $k) {
      if ((string) ($accepted[$k] ?? '') !== (string) $requirements[$k]) {
        return FALSE;
      }
    }
    foreach (['asset', 'payTo'] as $k) {
      if (strcasecmp((string) ($accepted[$k] ?? ''), (string) $requirements[$k]) !== 0) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
