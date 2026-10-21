<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use GuzzleHttp\ClientInterface;

/**
 * Buys an x402 call: asks, signs one transfer, asks again paid.
 *
 * The other side of PaymentGate, for a Drupal site or a script that buys
 * from agents: no gas, no allowance, one signature by the buyer's key. On
 * EVM an EIP-3009 authorization; on Solana a USDC transfer whose fees the
 * facilitator pays.
 */
class Buyer {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly Signer|SolanaKey $wallet,
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

    // 1. The 402 says what to pay, to whom, on which chain.
    $first = $call();
    $header = $first->getHeaderLine(PaymentHeaders::REQUIRED);
    if ($first->getStatusCode() !== 402 || $header === '') {
      throw new \RuntimeException(sprintf('Expected 402, got %d: %s', $first->getStatusCode(), substr((string) $first->getBody(), 0, 300)));
    }
    $required = PaymentHeaders::decode($header);
    // The first offer on the wallet's chains.
    $solana = $this->wallet instanceof SolanaKey;
    $req = current(array_filter($required['accepts'] ?? [], fn($a) => str_starts_with((string) $a['network'], 'solana:') === $solana))
      ?: throw new \RuntimeException(sprintf('Nothing to pay on %s: %s', $solana ? 'Solana' : 'EVM', implode(', ', array_column($required['accepts'] ?? [], 'network'))));
    if ($approve) {
      $approve($req);
    }

    // 2. One transfer of exactly that, signed by the buyer.
    $payment = $solana ? $this->solana($required, $req) : $this->evm($required, $req);

    // 3. The same call, paid. It waits as long as the payment can settle: a
    // buyer that hangs up first may be charged for an answer it never reads.
    $paid = $call([PaymentHeaders::SIGNATURE => PaymentHeaders::encode($payment)], (int) $req['maxTimeoutSeconds'] + 30);
    $receipt = $paid->getHeaderLine(PaymentHeaders::RESPONSE);
    $receipt = $receipt === '' ? [] : PaymentHeaders::decode($receipt);
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
   * An EIP-3009 authorization of exactly the price, signed.
   */
  private function evm(array $required, array $req): array {
    $authorization = [
      'from' => $this->wallet->address(),
      'to' => $req['payTo'],
      'value' => (string) $req['amount'],
      'validAfter' => '0',
      'validBefore' => (string) (time() + (int) $req['maxTimeoutSeconds']),
      'nonce' => '0x' . bin2hex(random_bytes(32)),
    ];
    $digest = Eip712::hash(
      ['name' => $req['extra']['name'], 'version' => $req['extra']['version'], 'chainId' => (int) explode(':', $req['network'])[1], 'verifyingContract' => $req['asset']],
      [
        'TransferWithAuthorization' => [
        ['name' => 'from', 'type' => 'address'], ['name' => 'to', 'type' => 'address'], ['name' => 'value', 'type' => 'uint256'],
        ['name' => 'validAfter', 'type' => 'uint256'], ['name' => 'validBefore', 'type' => 'uint256'], ['name' => 'nonce', 'type' => 'bytes32'],
        ],
      ],
      'TransferWithAuthorization',
      $authorization,
    );
    return [
      'x402Version' => 2,
      'resource' => $required['resource'],
      'accepted' => ['extra' => ['assetTransferMethod' => 'eip3009'] + $req['extra']] + $req,
      'payload' => ['signature' => $this->wallet->sign($digest), 'authorization' => $authorization],
    ];
  }

  /**
   * A Solana transaction that pays exactly the price, signed by the buyer.
   *
   * @throws \RuntimeException
   *   A token this module does not know, or no node to ask a blockhash.
   */
  private function solana(array $required, array $req): array {
    $network = Networks::ALL[$req['network']] ?? NULL;
    // ponytail: the tokens in Networks only; another mint's decimals come
    // from getTokenSupply if a seller ever asks for one.
    if (($network['asset'] ?? NULL) !== $req['asset']) {
      throw new \RuntimeException("Unknown token {$req['asset']} on {$req['network']}.");
    }
    $blockhash = $req['extra']['recentBlockhash']
      ?? (new Rpc($this->httpClient, $network['rpc']))('getLatestBlockhash', [['commitment' => 'confirmed']])['value']['blockhash'];
    $memo = $req['extra']['memo'] ?? bin2hex(random_bytes(16));
    $message = Solana::message($req, $this->wallet->address(), $blockhash, $network['decimals'], $memo);
    return [
      'x402Version' => 2,
      'resource' => $required['resource'],
      'accepted' => $req,
      'payload' => ['transaction' => Solana::transaction($message, $this->wallet)],
    ];
  }

}
