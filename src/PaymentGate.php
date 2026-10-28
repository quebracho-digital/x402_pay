<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Site\Settings;
use Drupal\x402_pay\Event\PaymentEvent;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FacilitatorException;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\SolanaExact;
use Quebracho\X402\X402Middleware;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Charges for a Drupal response over x402, and settles only on a 2xx.
 *
 * The protocol (402, verifying the payment, claiming it once, settling only
 * a 2xx, the receipt header) is the library's X402Middleware. This class is
 * what is Drupal's around it: the price and payTo of this request, the
 * facilitator the site configured, the payment log and event, the deadline,
 * Server-Timing, and the bridge between Symfony and PSR-7 messages.
 *
 * The flow: verify, claim, run the handler, 2xx? settle, hand over the body
 * only once the money settled. A failed handler costs the buyer nothing; a
 * failed settlement gets the buyer nothing.
 */
class PaymentGate {

  public function __construct(
    private readonly Facilitators $facilitators,
    private readonly PaymentLog $log,
    private readonly ClaimStore $claims,
    private readonly HttpFactory $psr17,
    private readonly HttpMessageFactoryInterface $psrFactory,
    private readonly HttpFoundationFactoryInterface $foundationFactory,
    private readonly ?EventDispatcherInterface $eventDispatcher = NULL,
    private readonly ?Settings $settings = NULL,
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
   * @param \Quebracho\X402\Signer|null $seller
   *   The payTo key, for the facilitator's seller proof; NULL when a Circle
   *   API key authenticates instead.
   * @param callable $handler
   *   Produces the paid response. Runs only after the payment verified.
   */
  public function handle(Request $request, array $requirements, array $resource, ?Signer $seller, callable $handler): Response {
    $timings = [];
    // What happened, for the log: filled by the steps below as they run.
    $run = new \stdClass();
    $run->handlerStatus = NULL;
    $run->settlement = NULL;
    $run->payer = NULL;
    $run->error = NULL;
    $run->unavailable = NULL;

    try {
      // On Solana the buyer builds the transaction around the fee payer of
      // the facilitator that sponsors it, so the 402 names it.
      if (str_starts_with($requirements['network'], 'solana:') && empty($requirements['extra']['feePayer'])) {
        $requirements['extra']['feePayer'] = $this->facilitators->feePayer($requirements['network'])
          ?? throw new FacilitatorException('it names no fee payer for ' . $requirements['network']);
      }
      $facilitator = $this->facilitators->for($requirements['network'], $seller);
    }
    catch (FacilitatorException $e) {
      return new JsonResponse(['error' => 'Payment facilitator unavailable: ' . $e->getMessage()], 502);
    }
    $timed = function (string $step, callable $do) use (&$timings): mixed {
      $start = hrtime(TRUE);
      try {
        return $do();
      }
      finally {
        $timings[$step] = round((hrtime(TRUE) - $start) / 1e6, 1);
      }
    };

    // What the chain alone knows (balance, a spent nonce) is the
    // facilitator's to say; the signature and the terms the library checked.
    // LIBRARY-FEEDBACK G3: X402Middleware has no online verify step.
    $verify = function (array $payment, array $req) use ($facilitator, $timed, $run): ?string {
      try {
        $verified = $timed('verify', fn() => $facilitator->verify($payment, $req));
      }
      catch (FacilitatorException $e) {
        $run->unavailable = $e->getMessage();
        return 'facilitator_unavailable';
      }
      $run->payer = $verified['payer'] ?? NULL;
      return empty($verified['isValid']) ? ($verified['invalidReason'] ?? 'invalid_payment') : NULL;
    };

    $settle = function (array $payment, array $req) use ($facilitator, $timed, $run): array {
      try {
        $receipt = $timed('settle', fn() => $facilitator->settle($payment, $req));
      }
      catch (FacilitatorException $e) {
        // LIBRARY-FEEDBACK G5: a throwing $settle becomes a plain 402 with
        // 'unexpected_settle_error'; we tell the buyer it was not them.
        $run->unavailable = $e->getMessage();
        $receipt = ['success' => FALSE, 'errorReason' => 'facilitator_unavailable', 'transaction' => '', 'network' => $req['network']];
      }
      $run->settlement = $receipt;
      if (empty($receipt['success'])) {
        // Nothing broadcast: the same payment may be offered again. A
        // transaction sent but not confirmed (settlement_pending) stays
        // claimed, since it may still land.
        if (($receipt['transaction'] ?? '') === '') {
          $this->claims->release(Settlement::key($payment));
        }
        return $receipt;
      }
      return [
        'success' => TRUE,
        'transaction' => $receipt['transaction'],
        'network' => $receipt['network'] ?? $req['network'],
        'payer' => $receipt['payer'] ?? $run->payer,
      ];
    };

    // The payment is already decoded and verified when the handler runs.
    $release = fn() => $this->claims->release(Settlement::key(PaymentHeaders::decode((string) $request->headers->get(PaymentHeaders::SIGNATURE))));
    $overDeadline = $this->deadline($request);
    $paid = function () use ($handler, $timed, $run, $release, $overDeadline): ResponseInterface {
      $response = $timed('handler', $handler);
      $run->handlerStatus = $response->getStatusCode();
      // After settling (the upfront flow) the money moved: keep the claim.
      $unspent = $run->settlement === NULL;
      if (!$response->isSuccessful()) {
        // Nothing delivered, nothing charged: the payment is still good.
        $unspent && $release();
      }
      elseif ($unspent && $overDeadline && $overDeadline()) {
        // A proxy that drops slow responses (Cloudflare's tunnel: 524 at
        // 100 s) leaves the buyer without the body, so charging them would
        // be for nothing.
        $run->error = 'deadline';
        $release();
        $response = new JsonResponse(['error' => 'Took too long: not charged, try a smaller input'], 504);
      }
      return $this->psrFactory->createResponse($response);
    };
    $inner = new class($paid) implements RequestHandlerInterface {

      public function __construct(private readonly \Closure $run) {}

      public function handle(ServerRequestInterface $request): ResponseInterface {
        return ($this->run)();
      }

    };

    $middleware = new X402Middleware(
      schemes: [new EvmExact(), new SolanaExact()],
      price: fn() => [$requirements],
      claim: $this->claims,
      responses: $this->psr17,
      streams: $this->psr17,
      settle: $settle,
      events: $this->eventDispatcher,
      // LIBRARY-FEEDBACK G2, G3: the 402's `resource` with description and
      // mimeType; an online verify between the offline one and the claim.
      resource: fn() => array_intersect_key($resource, array_flip(['url', 'description', 'mimeType'])),
      verify: $verify,
    );
    $response = $this->foundationFactory->createResponse($middleware->process($this->psrFactory->createRequest($request), $inner));

    if ($run->unavailable !== NULL) {
      // The facilitator, not the buyer, is the problem: 502, as before.
      $response = new JsonResponse(['error' => 'Payment facilitator unavailable: ' . $run->unavailable], 502);
    }
    $this->recordRun($request, $requirements, $resource, $run);
    $response->headers->set('Server-Timing', implode(', ', array_map(fn($step, $ms) => "x402-$step;dur=$ms", array_keys($timings), $timings)));
    return $response;
  }

  /**
   * Logs the outcome of a payment that reached the handler or the settle.
   *
   * A 402 for a missing, invalid or repeated payment is not an attempt to
   * sell, and is not logged.
   */
  private function recordRun(Request $request, array $requirements, array $resource, \stdClass $run): void {
    $settlement = $run->settlement;
    if ($run->handlerStatus === NULL && $settlement === NULL) {
      return;
    }
    $entry = [
      'resource' => $resource['url'],
      'network' => $requirements['network'],
      'amount' => $requirements['amount'],
      'pay_to' => $requirements['payTo'],
      'payer' => $settlement['payer'] ?? $run->payer,
      'handler_status' => $run->handlerStatus,
      'transaction' => $settlement['transaction'] ?? NULL,
      'entity_type' => $resource['entity_type'] ?? NULL,
      'entity_id' => $resource['entity_id'] ?? NULL,
    ];
    if ($settlement === NULL) {
      $this->record($request, ['status' => 'not_settled', 'error' => $run->error] + $entry);
    }
    elseif (!empty($settlement['success'])) {
      $failed = $run->handlerStatus !== NULL && ($run->handlerStatus < 200 || $run->handlerStatus >= 300);
      $this->record($request, ['status' => 'settled', 'error' => $failed ? 'delivery failed after settling: refund' : NULL] + $entry);
    }
    elseif ($run->handlerStatus !== NULL) {
      // Delivered but not paid: the body was withheld. (Settling that fails
      // before the handler ran, in the upfront flow, moved nothing.)
      $this->record($request, ['status' => 'failed', 'error' => $settlement['errorReason'] ?? NULL] + $entry);
    }
  }

  /**
   * A check for whether the request has run past the site's deadline.
   *
   * Off unless settings.php sets it, e.g. $settings['x402_pay_deadline'] = 95;
   * ponytail: one limit for the whole site; long jobs go async later.
   */
  private function deadline(Request $request): ?\Closure {
    $deadline = $this->settings?->get('x402_pay_deadline');
    $started = (float) $request->server->get('REQUEST_TIME_FLOAT', microtime(TRUE));
    return $deadline ? fn(): bool => microtime(TRUE) - $started > $deadline : NULL;
  }

  /**
   * Logs an outcome, then tells whoever listens (PaymentEvent).
   */
  private function record(Request $request, array $entry): void {
    $this->log->record($entry);
    $this->eventDispatcher?->dispatch(new PaymentEvent($entry, $request));
  }

}
