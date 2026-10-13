<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use GuzzleHttp\ClientInterface;

/**
 * One chain's JSON-RPC node: `$rpc('eth_call', [...])` returns the result.
 *
 * An answer with an error (a revert, a rejected transaction) throws
 * \RuntimeException with the node's message; an unreachable node throws
 * Guzzle's exception.
 */
final class Rpc {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly string $url,
  ) {}

  /**
   * One call; its result.
   *
   * @throws \RuntimeException
   *   The node answered with an error.
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   The node could not be reached.
   */
  public function __invoke(string $method, array $params): mixed {
    $response = json_decode((string) $this->httpClient->request('POST', $this->url, [
      'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
      'timeout' => 20,
    ])->getBody(), TRUE);
    if (isset($response['error'])) {
      throw new \RuntimeException("$method: " . ($response['error']['message'] ?? 'error'));
    }
    // A null result is an answer: a receipt not mined yet.
    return array_key_exists('result', $response ?? []) ? $response['result'] : throw new \RuntimeException("$method: no result");
  }

  /**
   * A 0x-hex quantity as a number.
   */
  public static function number(string $hex): \GMP {
    return gmp_init(ltrim(substr($hex, 2), '0') ?: '0', 16);
  }

  /**
   * Hex (an address or a number) left-padded to a 32-byte ABI word.
   */
  public static function word(string $hex): string {
    return str_pad(strtolower(str_starts_with($hex, '0x') ? substr($hex, 2) : $hex), 64, '0', STR_PAD_LEFT);
  }

}
