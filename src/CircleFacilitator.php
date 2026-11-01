<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Quebracho\X402\FacilitatorException as LibraryFacilitatorException;
use Quebracho\X402\HttpFacilitator;
use Quebracho\X402\Signer as LibrarySigner;

@trigger_error('Drupal\x402_pay\CircleFacilitator is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use \Quebracho\X402\HttpFacilitator with HttpFacilitator::CIRCLE instead. See https://www.drupal.org/project/x402_pay', E_USER_DEPRECATED);

/**
 * Circle's facilitator, with the 1.0.0-beta2 calls.
 *
 * @deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use
 *   \Quebracho\X402\HttpFacilitator with HttpFacilitator::CIRCLE instead, or
 *   the x402_pay.facilitators service to get the one a network uses.
 * @see https://www.drupal.org/project/x402_pay
 */
class CircleFacilitator {

  public const BASE = HttpFacilitator::CIRCLE;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly string $base = self::BASE,
    private readonly int $pollDelaySeconds = 1,
    private readonly int $pollAttempts = 5,
    private readonly ?ConfigFactoryInterface $config = NULL,
    private readonly ?KeyRepositoryInterface $keys = NULL,
  ) {}

  /**
   * Checks a payment without moving money.
   *
   * @throws \Quebracho\X402\FacilitatorException
   *   Unreachable, or not an x402 answer.
   */
  public function verify(array $payload, array $requirements, ?LibrarySigner $seller): array {
    return $this->client($seller)->verify($payload, $requirements);
  }

  /**
   * Moves the money.
   *
   * @throws \Quebracho\X402\FacilitatorException
   *   Unreachable, or not an x402 answer.
   */
  public function settle(array $payload, array $requirements, ?LibrarySigner $seller): array {
    return $this->client($seller)->settle($payload, $requirements);
  }

  /**
   * The API key, or NULL in keyless mode.
   *
   * @throws \Quebracho\X402\FacilitatorException
   *   API key mode, but the key is missing.
   */
  public function apiKey(): ?string {
    $settings = $this->config?->get('x402_pay.settings')->get('facilitator') ?? [];
    if (($settings['auth'] ?? 'keyless') !== 'api_key') {
      return NULL;
    }
    $key = trim((string) $this->keys?->getKey((string) ($settings['api_key_id'] ?? ''))?->getKeyValue());
    return $key !== '' ? $key : throw new LibraryFacilitatorException('No Circle API key configured.');
  }

  /**
   * The library's client for Circle.
   */
  private function client(?LibrarySigner $seller): HttpFacilitator {
    if (!$this->httpClient instanceof PsrClientInterface) {
      throw new LibraryFacilitatorException('Circle needs a PSR-18 HTTP client, such as GuzzleHttp\Client.');
    }
    $psr17 = new HttpFactory();
    return new HttpFacilitator($this->httpClient, $psr17, $psr17, $this->base, $this->apiKey(), $seller, $this->pollDelaySeconds, $this->pollAttempts);
  }

}
