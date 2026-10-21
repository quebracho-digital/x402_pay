<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Any x402 facilitator, by URL: /verify, /settle and /supported.
 *
 * Set per network (facilitator.remote), e.g. a Solana facilitator: on
 * Solana the facilitator sponsors the fees, and the buyer builds the
 * transaction around its fee payer, which /supported names.
 *
 * @see https://github.com/coinbase/x402/blob/main/specs/x402-specification-v2.md
 */
class RemoteFacilitator {

  /**
   * How long /supported is trusted, in seconds.
   */
  private const SUPPORTED_TTL = 3600;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $config,
    private readonly KeyRepositoryInterface $keys,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Whether a remote facilitator settles the network.
   */
  public function handles(string $network): bool {
    return $this->site($network) !== NULL;
  }

  /**
   * Checks a payment without moving money.
   *
   * @return array
   *   isValid, payer, and invalidReason when not valid.
   *
   * @throws \Drupal\x402_pay\FacilitatorException
   *   Not configured, unreachable, or not an x402 answer.
   */
  public function verify(array $payload, array $requirements, ?Signer $seller = NULL): array {
    return $this->call('verify', $payload, $requirements);
  }

  /**
   * Settles a payment.
   *
   * @return array
   *   success, transaction, network, payer, and errorReason when not a
   *   success.
   *
   * @throws \Drupal\x402_pay\FacilitatorException
   *   Not configured, unreachable, or not an x402 answer.
   */
  public function settle(array $payload, array $requirements, ?Signer $seller = NULL): array {
    return $this->call('settle', $payload, $requirements);
  }

  /**
   * The account that sponsors the fees of a Solana payment, or NULL.
   *
   * @throws \Drupal\x402_pay\FacilitatorException
   *   Not configured, or /supported unreachable.
   */
  public function feePayer(string $network): ?string {
    foreach ($this->supported($network) as $kind) {
      if (($kind['network'] ?? '') === $network && ($kind['scheme'] ?? '') === 'exact' && isset($kind['extra']['feePayer'])) {
        return (string) $kind['extra']['feePayer'];
      }
    }
    return NULL;
  }

  /**
   * What the facilitator settles, as /supported lists it ("kinds").
   */
  private function supported(string $network): array {
    $cid = 'x402_pay.supported:' . hash('sha256', $this->site($network)['url'] ?? '');
    if ($hit = $this->cache->get($cid)) {
      return $hit->data;
    }
    $kinds = $this->request($network, 'GET', '/supported')['kinds'] ?? [];
    $this->cache->set($cid, $kinds, time() + self::SUPPORTED_TTL);
    return $kinds;
  }

  /**
   * Sends a payment and its requirements to /verify or /settle.
   */
  private function call(string $purpose, array $payload, array $requirements): array {
    return $this->request($requirements['network'], 'POST', "/$purpose", [
      'x402Version' => 2,
      'paymentPayload' => $payload,
      'paymentRequirements' => $requirements,
    ]);
  }

  /**
   * Calls the network's facilitator and returns its JSON.
   *
   * A refusal some facilitators send as a 4xx with an x402 body (isValid or
   * success false) is an answer, not a failure.
   */
  private function request(string $network, string $method, string $path, ?array $json = NULL): array {
    $site = $this->site($network) ?? throw new FacilitatorException("No facilitator settles $network.");
    $options = ['timeout' => 30, 'headers' => []];
    if ($json !== NULL) {
      $options['json'] = $json;
    }
    $key = trim((string) $this->keys->getKey($site['key_id'] ?? '')?->getKeyValue());
    if ($key !== '') {
      $options['headers']['Authorization'] = "Bearer $key";
    }
    try {
      $response = $this->httpClient->request($method, rtrim($site['url'], '/') . $path, $options);
    }
    catch (BadResponseException $e) {
      $body = json_decode((string) $e->getResponse()->getBody(), TRUE);
      if (is_array($body) && (isset($body['isValid']) || isset($body['success']))) {
        return $body;
      }
      throw new FacilitatorException($body['error'] ?? $body['message'] ?? 'http_' . $e->getResponse()->getStatusCode(), $e->getResponse()->getStatusCode(), $e);
    }
    catch (GuzzleException $e) {
      throw new FacilitatorException($e->getMessage(), 0, $e);
    }
    return json_decode((string) $response->getBody(), TRUE) ?? throw new FacilitatorException('Not JSON.');
  }

  /**
   * The network's facilitator: url and key_id, or NULL.
   */
  private function site(string $network): ?array {
    $site = $this->config->get('x402_pay.settings')->get('facilitator')['remote'][$network] ?? NULL;
    return !empty($site['url']) ? $site : NULL;
  }

}
