<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\x402_pay\Event\PaymentEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Charges for a response over x402, and settles only when it is a 2xx.
 *
 * The flow: verify → run the handler → 2xx? settle → hand over the body
 * only once the money settled. A failed handler costs the buyer nothing;
 * a failed settlement gets the buyer nothing. Each paid response says how
 * long each step took, in a Server-Timing header.
 */
class PaymentGate {


  /**
   * Milliseconds per step of the current payment, for Server-Timing.
   */
  private array $timings = [];

  public function __construct(
    private readonly CircleFacilitator $facilitator,
    private readonly PaymentLog $log,
    private readonly ?ChainFacilitator $chain = NULL,
    private readonly ?EventDispatcherInterface $eventDispatcher = NULL,
    private readonly ?Settings $settings = NULL,
    private readonly ?LockBackendInterface $lock = NULL,
    private readonly ?RemoteFacilitator $remote = NULL,
  ) {}

  /**
   * Charges for the handler's response: 402, verify, run, settle.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The buyer's request, with PAYMENT-SIGNATURE once it pays.
   * @param array $requirements
   *   One x402 PaymentRequirements (scheme, network, amount, asset, payTo,
   *   maxTimeoutSeconds, extra). Set by the caller, so price and payTo can
   *   differ per listing.
   * @param array $resource
   *   url, description, mimeType; entity_type and entity_id, logged only.
   * @param \Drupal\x402_pay\Signer|null $seller
   *   The payTo key, for the facilitator's seller proof; NULL when a Circle
   *   API key authenticates instead.
   * @param callable $handler
   *   Produces the paid response. Runs only after the payment verified.
   */
  public function handle(Request $request, array $requirements, array $resource, ?Signer $seller, callable $handler): Response {
    // On Solana the buyer builds the transaction around the fee payer of
    // the facilitator that sponsors it, so the 402 names it.
    if (str_starts_with($requirements['network'], 'solana:') && empty($requirements['extra']['feePayer'])) {
      try {
        $requirements['extra']['feePayer'] = $this->remote?->feePayer($requirements['network'])
          ?? throw new FacilitatorException('it names no fee payer for ' . $requirements['network']);
      }
      catch (FacilitatorException $e) {
        return new JsonResponse(['error' => 'Payment facilitator unavailable: ' . $e->getMessage()], 502);
      }
    }
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

    // One authorization, one handler run at a time: the same payment sent
    // N times at once would run the handler N times and send N settlements,
    // all but one reverting on our gas (x402Scope SR5). Once it settles the
    // nonce is spent on chain, so verify refuses it from then on.
    // EVM: the authorization's signer and nonce. Solana: the signed
    // transaction itself, which lands once.
    $auth = $payment['payload']['authorization'] ?? NULL;
    $claim = 'x402_pay.payment:' . hash('sha256', $auth
      ? strtolower(($auth['from'] ?? '') . ':' . ($auth['nonce'] ?? ''))
      : (string) ($payment['payload']['transaction'] ?? ''));
    // Longer than the slowest handler (10 min) plus settling.
    if ($this->lock && !$this->lock->acquire($claim, 900)) {
      return new JsonResponse(['error' => 'This payment is already being used'], 409);
    }
    try {
      return $this->charge($request, $requirements, $resource, $seller, $handler, $payment);
    }
    finally {
      $this->lock?->release($claim);
    }
  }

  /**
   * Verify, run the handler, settle: handle() once the payment is claimed.
   */
  private function charge(Request $request, array $requirements, array $resource, ?Signer $seller, callable $handler, array $payment): Response {
    // Our own facilitator for the networks it settles, then the one set for
    // the network, then Circle.
    $facilitator = match (TRUE) {
      (bool) $this->chain?->handles($requirements['network']) => $this->chain,
      (bool) $this->remote?->handles($requirements['network']) => $this->remote,
      default => $this->facilitator,
    };
    $this->timings = [];
    try {
      $verified = $this->timed('verify', fn() => $facilitator->verify($payment, $requirements, $seller));
    }
    catch (FacilitatorException $e) {
      return $this->withTimings(new JsonResponse(['error' => 'Payment facilitator unavailable: ' . $e->getMessage()], 502));
    }
    if (empty($verified['isValid'])) {
      return $this->withTimings($this->required($requirements, $resource, $verified['invalidReason'] ?? 'invalid_payment'));
    }

    $response = $this->timed('handler', $handler);
    $entry = [
      'resource' => $resource['url'],
      'network' => $requirements['network'],
      'amount' => $requirements['amount'],
      'pay_to' => $requirements['payTo'],
      'payer' => $verified['payer'] ?? NULL,
      'handler_status' => $response->getStatusCode(),
      'transaction' => NULL,
      'entity_type' => $resource['entity_type'] ?? NULL,
      'entity_id' => $resource['entity_id'] ?? NULL,
    ];
    if (!$response->isSuccessful()) {
      $this->record($request, ['status' => 'not_settled'] + $entry);
      return $this->withTimings($response);
    }
    // A proxy that drops slow responses (Cloudflare's tunnel: 524 at 100 s)
    // leaves the buyer without the body, so charging them would be for
    // nothing. Off unless settings.php sets it, e.g.
    // $settings['x402_pay_deadline'] = 95;
    // ponytail: one limit for the whole site; long jobs go async later.
    $deadline = $this->settings?->get('x402_pay_deadline');
    $started = (float) $request->server->get('REQUEST_TIME_FLOAT', microtime(TRUE));
    if ($deadline && microtime(TRUE) - $started > $deadline) {
      $this->record($request, ['status' => 'not_settled', 'error' => 'deadline'] + $entry);
      return $this->withTimings(new JsonResponse(['error' => sprintf('Took over %d s: not charged, try a smaller input', $deadline)], 504));
    }

    try {
      $settlement = $this->timed('settle', fn() => $facilitator->settle($payment, $requirements, $seller));
    }
    catch (FacilitatorException $e) {
      $this->record($request, ['status' => 'failed', 'error' => $e->getMessage()] + $entry);
      return $this->withTimings(new JsonResponse(['error' => 'Payment facilitator unavailable: ' . $e->getMessage()], 502));
    }
    if (empty($settlement['success'])) {
      $this->record($request, ['status' => 'failed', 'error' => $settlement['errorReason'] ?? NULL] + $entry);
      $failed = new JsonResponse(['error' => $settlement['errorReason'] ?? 'settlement_failed'], 402);
      $failed->headers->set(PaymentHeaders::RESPONSE, PaymentHeaders::encode($settlement));
      return $this->withTimings($failed);
    }

    $this->record($request, [
      'status' => 'settled',
      'transaction' => $settlement['transaction'],
      'payer' => $settlement['payer'] ?? $entry['payer'],
    ] + $entry);
    $response->headers->set(PaymentHeaders::RESPONSE, PaymentHeaders::encode([
      'success' => TRUE,
      'transaction' => $settlement['transaction'],
      'network' => $settlement['network'] ?? $requirements['network'],
      'payer' => $settlement['payer'] ?? $entry['payer'],
    ]));
    return $this->withTimings($response);
  }

  /**
   * Logs an outcome, then tells whoever listens (PaymentEvent).
   */
  private function record(Request $request, array $entry): void {
    $this->log->record($entry);
    $this->eventDispatcher?->dispatch(new PaymentEvent($entry, $request));
  }

  /**
   * Runs one step and keeps how long it took, even when it throws.
   */
  private function timed(string $step, callable $run): mixed {
    $start = hrtime(TRUE);
    try {
      return $run();
    }
    finally {
      $this->timings[$step] = round((hrtime(TRUE) - $start) / 1e6, 1);
    }
  }

  /**
   * Adds how long each step took as a Server-Timing header.
   */
  private function withTimings(Response $response): Response {
    $response->headers->set('Server-Timing', implode(', ', array_map(fn($step, $ms) => "x402-$step;dur=$ms", array_keys($this->timings), $this->timings)));
    return $response;
  }

  /**
   * The 402 that asks for payment, as JSON and as PAYMENT-REQUIRED.
   */
  private function required(array $requirements, array $resource, string $error): Response {
    $required = ['x402Version' => 2, 'error' => $error, 'resource' => array_intersect_key($resource, array_flip(['url', 'description', 'mimeType'])), 'accepts' => [$requirements]];
    $response = new JsonResponse($required, 402);
    $response->headers->set(PaymentHeaders::REQUIRED, PaymentHeaders::encode($required));
    return $response;
  }

  /**
   * Whether the buyer pays exactly what we ask.
   *
   * Same scheme, chain, token, seller and amount. EVM addresses compare
   * case-insensitively; base58 (Solana) is case-sensitive.
   */
  private function matches(array $accepted, array $requirements): bool {
    foreach (['scheme', 'network', 'amount'] as $k) {
      if ((string) ($accepted[$k] ?? '') !== (string) $requirements[$k]) {
        return FALSE;
      }
    }
    $evm = str_starts_with($requirements['network'], 'eip155:');
    foreach (['asset', 'payTo'] as $k) {
      [$a, $b] = [(string) ($accepted[$k] ?? ''), (string) $requirements[$k]];
      if ($evm ? strcasecmp($a, $b) !== 0 : $a !== $b) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
