<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Quebracho\X402\Facilitator as LibraryFacilitator;
use Quebracho\X402\FacilitatorException as LibraryFacilitatorException;
use Quebracho\X402\Signer as LibrarySigner;

@trigger_error('Drupal\x402_pay\RemoteFacilitator is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use the x402_pay.facilitators service or \Quebracho\X402\HttpFacilitator instead. See https://www.drupal.org/project/x402_pay', E_USER_DEPRECATED);

/**
 * Any x402 facilitator by URL, set per network, with the 1.0.0-beta2 calls.
 *
 * The settings are read by the x402_pay.facilitators service, which builds
 * the library's \Quebracho\X402\HttpFacilitator; the constructor
 * arguments are kept only so old code still constructs it.
 *
 * @deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use
 *   the x402_pay.facilitators service instead.
 * @see https://www.drupal.org/project/x402_pay
 */
class RemoteFacilitator {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $config,
    private readonly KeyRepositoryInterface $keys,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Whether this facilitator settles the network.
   */
  public function handles(string $network): bool {
    return $this->facilitators()->hasRemote($network);
  }

  /**
   * Checks a payment without moving money.
   *
   * @throws \Quebracho\X402\FacilitatorException
   *   Unreachable, or not an x402 answer.
   */
  public function verify(array $payload, array $requirements, ?LibrarySigner $seller = NULL): array {
    return $this->client($requirements['network'], $seller)->verify($payload, $requirements);
  }

  /**
   * Moves the money.
   *
   * @throws \Quebracho\X402\FacilitatorException
   *   Unreachable, or not an x402 answer.
   */
  public function settle(array $payload, array $requirements, ?LibrarySigner $seller = NULL): array {
    return $this->client($requirements['network'], $seller)->settle($payload, $requirements);
  }

  /**
   * The account that sponsors the fees of a Solana payment, or NULL.
   *
   * @throws \Quebracho\X402\FacilitatorException
   *   Unreachable, or not an x402 answer.
   */
  public function feePayer(string $network): ?string {
    return $this->facilitators()->feePayer($network);
  }

  /**
   * The library's facilitator for the network, if this one handles it.
   */
  private function client(string $network, ?LibrarySigner $seller): LibraryFacilitator {
    if (!$this->handles($network)) {
      throw new LibraryFacilitatorException("Not set to settle $network.");
    }
    return $this->facilitators()->for($network, $seller);
  }

  /**
   * The service that reads the settings.
   */
  private function facilitators(): Facilitators {
    // Deprecated shell: its 1.0.0-beta2 constructor is kept, so the service
    // cannot be injected.
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('x402_pay.facilitators'); // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
  }

}
