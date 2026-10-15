<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use GuzzleHttp\ClientInterface;

/**
 * Buys an x402 call: asks, signs one EIP-3009 transfer, asks again paid.
 *
 * The other side of PaymentGate, for a Drupal site or a script that buys
 * from agents: no gas, no allowance, one signature by the buyer's key.
 */
class Buyer {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly Signer $wallet,
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
    $req = $required['accepts'][0];
    if ($approve) {
      $approve($req);
    }

    // 2. One transfer of exactly that, signed by the buyer.
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
    $payment = [
      'x402Version' => 2,
      'resource' => $required['resource'],
      'accepted' => ['extra' => ['assetTransferMethod' => 'eip3009'] + $req['extra']] + $req,
      'payload' => ['signature' => $this->wallet->sign($digest), 'authorization' => $authorization],
    ];

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

}
