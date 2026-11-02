<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Quebracho\X402\Facilitator as LibraryFacilitator;
use Quebracho\X402\FacilitatorException as LibraryFacilitatorException;
use Quebracho\X402\Signer as LibrarySigner;

@trigger_error('Drupal\x402_pay\ChainFacilitator is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use the x402_pay.facilitators service or \Quebracho\X402\ChainFacilitator instead. See https://www.drupal.org/project/x402_pay', E_USER_DEPRECATED);

/**
 * Our own on-chain facilitator, with the 1.0.0-beta2 calls.
 *
 * For the networks the site settles itself (own_networks).
 *
 * The settings are read by the x402_pay.facilitators service, which builds
 * the library's \Quebracho\X402\ChainFacilitator; the constructor
 * arguments are kept only so old code still constructs it.
 *
 * @deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use
 *   the x402_pay.facilitators service instead.
 * @see https://www.drupal.org/project/x402_pay
 */
class ChainFacilitator {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $config,
    private readonly KeyRepositoryInterface $keys,
    private readonly LockBackendInterface $lock,
    private readonly int $pollDelayMs = 500,
    private readonly int $pollAttempts = 40,
  ) {}

  /**
   * Whether this facilitator settles the network.
   */
  public function handles(string $network): bool {
    return $this->facilitators()->settlesItself($network);
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
