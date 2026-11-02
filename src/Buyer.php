<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Networks as LibraryNetworks;
use Quebracho\X402\PaymentHeaders as LibraryPaymentHeaders;
use Quebracho\X402\Rpc as LibraryRpc;
use Quebracho\X402\Signer as LibrarySigner;
use Quebracho\X402\SolanaExact;
use Quebracho\X402\SolanaKey as LibrarySolanaKey;

@trigger_error('Drupal\x402_pay\Buyer is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use \Quebracho\X402\PayingHttpClient instead. See https://www.drupal.org/project/x402_pay/issues/3634901', E_USER_DEPRECATED);

/**
 * Buys an x402 call: asks, signs one transfer, asks again paid.
 *
 * The payment is built by the library (EvmExact, SolanaExact); only the
 * calls and the array returned are kept from 1.0.0-beta2.
 *
 * @deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use
 *   \Quebracho\X402\PayingHttpClient with a \Quebracho\X402\Budget instead.
 * @see https://www.drupal.org/project/x402_pay/issues/3634901
 */
class Buyer {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LibrarySigner|LibrarySolanaKey $wallet,
  ) {}

  /**
   * The buyer's address.
   */
  public function address(): string {
    return $this->wallet->address();
  }

  /**
   * Calls $url and pays its 402.
   *
   * @param string $url
   *   The paid resource.
   * @param array|null $input
   *   Sent as a JSON POST body; NULL for a GET.
   * @param callable|null $approve
   *   Called with the payment requirements before signing; throws to refuse
   *   (a budget, a maximum price).
   *
   * @return array
   *   status, body, contentType, tx (NULL unless settled), amount (atomic),
   *   network, requirements.
   *
   * @throws \RuntimeException
   *   The first answer is not a 402 with requirements.
   */
  public function pay(string $url, ?array $input = NULL, ?callable $approve = NULL): array {
    $call = fn(array $headers = [], int $timeout = 30) => $this->httpClient->request($input === NULL ? 'GET' : 'POST', $url, [
      'http_errors' => FALSE,
      'timeout' => $timeout,
      'headers' => ['Accept' => '*/*'] + ($input === NULL ? [] : ['Content-Type' => 'application/json']) + $headers,
      'body' => $input === NULL ? NULL : json_encode($input ?: new \stdClass(), JSON_UNESCAPED_SLASHES),
    ]);

    $first = $call();
    $header = $first->getHeaderLine(LibraryPaymentHeaders::REQUIRED);
    if ($first->getStatusCode() !== 402 || $header === '') {
      throw new \RuntimeException(sprintf('Expected 402, got %d: %s', $first->getStatusCode(), substr((string) $first->getBody(), 0, 300)));
    }
    $required = LibraryPaymentHeaders::decode($header);
    // The first offer on the wallet's chains.
    $solana = $this->wallet instanceof LibrarySolanaKey;
    $req = current(array_filter($required['accepts'] ?? [], fn($a) => str_starts_with((string) $a['network'], 'solana:') === $solana))
      ?: throw new \RuntimeException(sprintf('Nothing to pay on %s: %s', $solana ? 'Solana' : 'EVM', implode(', ', array_column($required['accepts'] ?? [], 'network'))));
    if ($approve) {
      $approve($req);
    }

    $payment = ($solana ? $this->solana($req['network']) : new EvmExact($this->wallet))->pay($required, $req);

    // The same call, paid. It waits as long as the payment can settle: a
    // buyer that hangs up first may be charged for an answer it never reads.
    $paid = $call([LibraryPaymentHeaders::SIGNATURE => LibraryPaymentHeaders::encode($payment)], (int) $req['maxTimeoutSeconds'] + 30);
    $receipt = $paid->getHeaderLine(LibraryPaymentHeaders::RESPONSE);
    $receipt = $receipt === '' ? [] : LibraryPaymentHeaders::decode($receipt);
    return [
      'status' => $paid->getStatusCode(),
      'body' => (string) $paid->getBody(),
      'contentType' => $paid->getHeaderLine('Content-Type'),
      'tx' => !empty($receipt['success']) ? $receipt['transaction'] : NULL,
      'amount' => (string) $req['amount'],
      'network' => $req['network'],
      'requirements' => $req,
    ];
  }

  /**
   * The Solana scheme, with the network's node for the blockhash.
   */
  private function solana(string $network): SolanaExact {
    if (!$this->httpClient instanceof PsrClientInterface) {
      throw new \RuntimeException('Paying on Solana needs a PSR-18 HTTP client, such as GuzzleHttp\Client.');
    }
    $psr17 = new HttpFactory();
    return new SolanaExact($this->wallet, new LibraryRpc($this->httpClient, $psr17, $psr17, LibraryNetworks::get($network)['rpc']));
  }

}
