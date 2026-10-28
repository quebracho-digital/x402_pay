<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Quebracho\X402\Facilitator\ChainFacilitator;
use Quebracho\X402\Facilitator\CircleFacilitator;
use Quebracho\X402\Facilitator\Facilitator;
use Quebracho\X402\Facilitator\HttpFacilitator;
use Quebracho\X402\FacilitatorException;
use Quebracho\X402\Networks;
use Quebracho\X402\Rpc;
use Quebracho\X402\Signer;

/**
 * Picks the facilitator that settles a network, from the site's settings.
 *
 * The protocol clients (Circle, any x402 facilitator by URL, our own on
 * chain) live in the library. Here only what is Drupal's: which of them a
 * network uses, where its keys come from (Key module), and the lock that
 * serializes our gas wallet.
 */
class Facilitators {
  // LIBRARY-FEEDBACK G7: the Facilitator interface and its three clients
  // (HttpFacilitator, CircleFacilitator, ChainFacilitator) do not exist yet.


  /**
   * How long /supported is trusted, in seconds.
   */
  private const SUPPORTED_TTL = 3600;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly HttpFactory $psr17,
    private readonly ConfigFactoryInterface $config,
    private readonly KeyRepositoryInterface $keys,
    private readonly LockBackendInterface $lock,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Whether this site settles the network itself instead of Circle.
   *
   * EVM only: on Solana a remote facilitator settles.
   */
  public function settlesItself(string $network): bool {
    return str_starts_with($network, 'eip155:') && in_array($network, $this->settings()['own_networks'] ?? [], TRUE);
  }

  /**
   * Whether another facilitator, by URL, is set for the network.
   */
  public function hasRemote(string $network): bool {
    return $this->remote($network) !== NULL;
  }

  /**
   * The facilitator for a network: our own, the one set for it, or Circle.
   *
   * @param string $network
   *   A CAIP-2 id.
   * @param \Quebracho\X402\Signer|null $seller
   *   The payTo key, for Circle's keyless seller proof; NULL when an API key
   *   authenticates instead.
   *
   * @throws \Quebracho\X402\FacilitatorException
   *   No gas wallet key, or Circle in API key mode without its key.
   */
  public function for(string $network, ?Signer $seller): Facilitator {
    if ($this->settlesItself($network)) {
      return new ChainFacilitator(
        rpc: new Rpc($this->httpClient, $this->psr17, $this->psr17, Networks::get($network)['rpc']),
        gasWallet: $this->gasWallet(),
        serialize: $this->serialize(...),
      );
    }
    if ($site = $this->remote($network)) {
      return $this->http($site);
    }
    return new CircleFacilitator($this->httpClient, $this->psr17, $this->psr17, apiKey: $this->circleKey(), seller: $seller);
  }

  /**
   * The account that sponsors the fees of a Solana payment, or NULL.
   *
   * @throws \Quebracho\X402\FacilitatorException
   *   Not configured, or /supported unreachable.
   */
  public function feePayer(string $network): ?string {
    $site = $this->remote($network) ?? throw new FacilitatorException("No facilitator names a fee payer for $network.");
    $cid = 'x402_pay.fee_payer:' . hash('sha256', $site['url'] . $network);
    if ($hit = $this->cache->get($cid)) {
      return $hit->data ?: NULL;
    }
    $payer = $this->http($site)->feePayer($network);
    $this->cache->set($cid, (string) $payer, time() + self::SUPPORTED_TTL);
    return $payer;
  }

  /**
   * A facilitator by URL, with its bearer token if the site set one.
   */
  private function http(array $site): HttpFacilitator {
    return new HttpFacilitator($this->httpClient, $this->psr17, $this->psr17, $site['url'], $this->secret($site['key_id'] ?? ''));
  }

  /**
   * Runs $run holding a named lock: one sender, one nonce at a time.
   */
  private function serialize(string $name, callable $run): mixed {
    $name = 'x402_pay.' . $name;
    // wait() is TRUE while the lock is still taken.
    if (!$this->lock->acquire($name, 30) && ($this->lock->wait($name, 30) || !$this->lock->acquire($name, 30))) {
      throw new FacilitatorException('The gas wallet is busy.');
    }
    try {
      return $run();
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * The key that pays the gas of our own facilitator.
   *
   * @throws \Quebracho\X402\FacilitatorException
   *   No key, or not an EVM private key.
   */
  private function gasWallet(): Signer {
    try {
      return new Signer($this->secret((string) ($this->settings()['own_key_id'] ?? '')) ?? '');
    }
    catch (\InvalidArgumentException) {
      throw new FacilitatorException('No gas wallet key for our own facilitator.');
    }
  }

  /**
   * The Circle API key, when the site uses one instead of the keyless trial.
   *
   * @throws \Quebracho\X402\FacilitatorException
   *   API key mode, but the key is missing.
   */
  private function circleKey(): ?string {
    if (($this->settings()['auth'] ?? 'keyless') !== 'api_key') {
      return NULL;
    }
    return $this->secret((string) ($this->settings()['api_key_id'] ?? '')) ?? throw new FacilitatorException('No Circle API key configured.');
  }

  /**
   * A secret from the Key module, or NULL when empty.
   */
  private function secret(string $id): ?string {
    $value = trim((string) $this->keys->getKey($id)?->getKeyValue());
    return $value !== '' ? $value : NULL;
  }

  /**
   * The network's remote facilitator: url and key_id, or NULL.
   */
  private function remote(string $network): ?array {
    $site = $this->settings()['remote'][$network] ?? NULL;
    return !empty($site['url']) ? $site : NULL;
  }

  /**
   * The facilitator settings.
   */
  private function settings(): array {
    return $this->config->get('x402_pay.settings')->get('facilitator') ?? [];
  }

}
