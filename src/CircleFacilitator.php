<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Circle's Facilitator Service.
 *
 * Keyless (the trial): every call carries a seller proof signed by the payTo
 * key. With a Circle API key: a bearer token instead, so payTo needs no key
 * here and can be a cold wallet; settling once with the key binds payTo to
 * the Circle account.
 *
 * @see https://developers.circle.com/facilitator-service/quickstart
 * @see https://developers.circle.com/facilitator-service/keyless-trial
 */
class CircleFacilitator {

  public const BASE = 'https://api.circle.com/v1/facilitator/x402';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly string $base = self::BASE,
    private readonly int $pollDelaySeconds = 1,
    private readonly int $pollAttempts = 5,
    private readonly ?ConfigFactoryInterface $config = NULL,
    private readonly ?KeyRepositoryInterface $keys = NULL,
  ) {}

  /**
   * Checks a payment without moving money or using trial allowance.
   *
   * @return array
   *   isValid, payer, and invalidReason when not valid.
   */
  public function verify(array $payload, array $requirements, ?Signer $seller): array {
    return $this->call('verify', $payload, $requirements, $seller);
  }

  /**
   * Settles a payment. A pending answer is polled until it is final.
   *
   * @return array
   *   success, transaction, network, payer, and errorReason when not a
   *   success. Still pending after polling is not a success.
   */
  public function settle(array $payload, array $requirements, ?Signer $seller): array {
    $result = $this->call('settle', $payload, $requirements, $seller);
    $paymentId = $result['extensions']['settlement-status']['paymentId'] ?? NULL;
    for ($i = 0; empty($result['success']) && $paymentId && $i < $this->pollAttempts; $i++) {
      sleep($this->pollDelaySeconds);
      $status = $this->request('GET', "/status/$paymentId", '', 'status', $requirements['network'], $seller);
      if ($status['status'] === 'completed') {
        return [
          'success' => TRUE,
          'transaction' => $status['transaction'],
          'network' => $status['network'] ?? $requirements['network'],
          'payer' => $status['payer'] ?? $result['payer'] ?? NULL,
        ];
      }
      if ($status['status'] === 'failed') {
        return [
          'success' => FALSE,
          'errorReason' => $status['reason'] ?? 'settlement_failed',
          'transaction' => '',
          'network' => $requirements['network'],
          'payer' => $result['payer'] ?? NULL,
        ];
      }
    }
    return $result;
  }

  /**
   * Sends a payment and its requirements to /verify or /settle.
   */
  private function call(string $purpose, array $payload, array $requirements, ?Signer $seller): array {
    $body = json_encode(['x402Version' => 2, 'paymentPayload' => $payload, 'paymentRequirements' => $requirements], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return $this->request('POST', "/$purpose", $body, $purpose, $requirements['network'], $seller);
  }

  /**
   * The Circle API key, when the site uses one instead of the trial.
   *
   * @throws \Drupal\x402_pay\FacilitatorException
   *   API key mode, but the key is missing.
   */
  public function apiKey(): ?string {
    $settings = $this->config?->get('x402_pay.settings')->get('facilitator') ?? [];
    if (($settings['auth'] ?? 'keyless') !== 'api_key') {
      return NULL;
    }
    $key = trim((string) $this->keys?->getKey((string) ($settings['api_key_id'] ?? ''))?->getKeyValue());
    return $key !== '' ? $key : throw new FacilitatorException('No Circle API key configured.');
  }

  /**
   * Calls the facilitator, authenticated, and returns its JSON.
   */
  private function request(string $method, string $path, string $body, string $purpose, string $network, ?Signer $seller): array {
    if ($apiKey = $this->apiKey()) {
      $headers = ['Authorization' => "Bearer $apiKey"];
    }
    elseif ($seller) {
      $headers = ['Facilitator-Seller-Proof' => SellerProof::header($seller, $purpose, $method, $body, $network)];
    }
    else {
      throw new FacilitatorException('Keyless settling needs the payTo key: set a Circle API key to sell to a wallet whose key is not here.');
    }
    if ($body !== '') {
      $headers['Content-Type'] = 'application/json';
    }
    try {
      $response = $this->httpClient->request($method, $this->base . $path, [
        'headers' => $headers,
        'body' => $body,
        'timeout' => 30,
      ]);
    }
    catch (BadResponseException $e) {
      $error = json_decode((string) $e->getResponse()->getBody(), TRUE) ?: [];
      $reason = $error['errors'][0]['reason'] ?? $error['message'] ?? 'http_' . $e->getResponse()->getStatusCode();
      throw new FacilitatorException($reason, $e->getResponse()->getStatusCode(), $e);
    }
    catch (GuzzleException $e) {
      throw new FacilitatorException($e->getMessage(), 0, $e);
    }
    return json_decode((string) $response->getBody(), TRUE) ?? throw new FacilitatorException('Not JSON.');
  }

}
