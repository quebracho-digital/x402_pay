<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Our own facilitator: verifies and settles x402 `exact` payments on chain.
 *
 * The buyer signed an EIP-3009 transferWithAuthorization; this site sends
 * it to the token from its gas wallet. The token is the judge of the
 * signature, the balance, the time window and the nonce: verifying is that
 * call simulated (eth_call), settling is the same call sent. Here only what
 * the token cannot know: that it pays our seller our price.
 *
 * The gas wallet pays gas, nothing else; on Arc gas is USDC. It never holds
 * the sales: they go from the buyer to payTo directly.
 */
class ChainFacilitator {

  /**
   * Selector of transferWithAuthorization(…, uint8 v, bytes32 r, bytes32 s).
   */
  private const TRANSFER_WITH_AUTHORIZATION = '0xe3ee160e';

  /**
   * Seconds a payment must stay valid after verifying: settling comes next.
   */
  private const MARGIN = 3;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $config,
    private readonly KeyRepositoryInterface $keys,
    private readonly LockBackendInterface $lock,
    private readonly int $pollDelayMs = 500,
    private readonly int $pollAttempts = 40,
  ) {}

  /**
   * Whether this site settles the network itself instead of Circle.
   */
  public function handles(string $network): bool {
    return in_array($network, $this->settings()['own_networks'] ?? [], TRUE);
  }

  /**
   * Checks a payment without moving money.
   *
   * @return array
   *   isValid, payer, and invalidReason when not valid.
   *
   * @throws \Drupal\x402_pay\FacilitatorException
   *   No gas wallet, or the chain cannot be reached.
   */
  public function verify(array $payload, array $requirements, ?Signer $seller = NULL): array {
    $result = ['isValid' => FALSE, 'payer' => $payload['payload']['authorization']['from'] ?? NULL];
    $reason = $this->mismatch($payload, $requirements);
    if ($reason === NULL) {
      $gas = $this->gasWallet();
      try {
        $this->rpc($requirements['network'])('eth_call', [
          ['from' => $gas->address(), 'to' => $requirements['asset'], 'data' => self::calldata($payload)],
          'latest',
        ]);
        return ['isValid' => TRUE] + $result;
      }
      catch (\RuntimeException $e) {
        $reason = $e->getMessage();
      }
      catch (GuzzleException $e) {
        throw new FacilitatorException($e->getMessage(), 0, $e);
      }
    }
    return ['invalidReason' => $reason] + $result;
  }

  /**
   * Sends the payment on chain and waits for its receipt.
   *
   * @return array
   *   success, transaction, network, payer, and errorReason when not a
   *   success.
   *
   * @throws \Drupal\x402_pay\FacilitatorException
   *   No gas wallet, or the chain cannot be reached.
   */
  public function settle(array $payload, array $requirements, ?Signer $seller = NULL): array {
    $network = $requirements['network'];
    $result = [
      'success' => FALSE,
      'transaction' => '',
      'network' => $network,
      'payer' => $payload['payload']['authorization']['from'] ?? NULL,
    ];
    $reason = $this->mismatch($payload, $requirements);
    if ($reason !== NULL) {
      return ['errorReason' => $reason] + $result;
    }
    $gas = $this->gasWallet();
    $rpc = $this->rpc($network);
    $call = ['from' => $gas->address(), 'to' => $requirements['asset'], 'data' => self::calldata($payload)];
    // One sender, one nonce at a time.
    // ponytail: a lock per network serializes sales on it; several gas
    // wallets in rotation if that ever limits throughput.
    $lock = "x402_pay.chain_facilitator.$network";
    // wait() is TRUE while the lock is still taken.
    if (!$this->lock->acquire($lock, 30) && ($this->lock->wait($lock, 30) || !$this->lock->acquire($lock, 30))) {
      throw new FacilitatorException('The gas wallet is busy.');
    }
    try {
      $gasPrice = Rpc::number($rpc('eth_gasPrice', []));
      // Its estimate and a fifth more. A revert here is a bad payment.
      $limit = gmp_div_q(gmp_mul(Rpc::number($rpc('eth_estimateGas', [$call])), 12), 10);
      $raw = Transfer::sign($gas, [
        'chainId' => explode(':', $network)[1],
        'nonce' => gmp_strval(Rpc::number($rpc('eth_getTransactionCount', [$gas->address(), 'pending']))),
        'maxPriorityFeePerGas' => gmp_strval($gasPrice),
        // Twice today's price, so a busier block still takes it.
        'maxFeePerGas' => gmp_strval(gmp_mul($gasPrice, 2)),
        'gas' => gmp_strval($limit),
        'token' => $requirements['asset'],
        'data' => $call['data'],
      ]);
      $result['transaction'] = $rpc('eth_sendRawTransaction', [$raw]);
    }
    catch (\RuntimeException $e) {
      return ['errorReason' => $e->getMessage()] + $result;
    }
    catch (GuzzleException $e) {
      throw new FacilitatorException($e->getMessage(), 0, $e);
    }
    finally {
      $this->lock->release($lock);
    }

    // Arc is final in under a second; this waits up to 20 s by default.
    for ($i = 0; $i < $this->pollAttempts; $i++) {
      try {
        $receipt = $rpc('eth_getTransactionReceipt', [$result['transaction']]);
      }
      catch (\RuntimeException | GuzzleException) {
        $receipt = NULL;
      }
      if ($receipt) {
        return ($receipt['status'] ?? '') === '0x1'
          ? ['success' => TRUE] + $result
          : ['errorReason' => 'transaction_reverted'] + $result;
      }
      usleep($this->pollDelayMs * 1000);
    }
    // ponytail: sent but not mined in time: the buyer may still pay. A
    // receipt check on cron that delivers or logs it, if this ever happens.
    return ['errorReason' => 'settlement_pending'] + $result;
  }

  /**
   * Why the payment does not pay our price to our seller, or NULL.
   *
   * After NULL, calldata() does not throw.
   */
  private function mismatch(array $payload, array $requirements): ?string {
    try {
      self::calldata($payload);
    }
    catch (InvalidPaymentException $e) {
      return 'invalid_exact_evm_payload: ' . $e->getMessage();
    }
    $auth = $payload['payload']['authorization'];
    $now = time();
    return match (TRUE) {
      strcasecmp((string) ($auth['to'] ?? ''), (string) $requirements['payTo']) !== 0 => 'invalid_exact_evm_payload_recipient_mismatch',
      (string) ($auth['value'] ?? '') !== (string) $requirements['amount'] => 'invalid_exact_evm_payload_authorization_value',
      (int) ($auth['validAfter'] ?? 0) > $now => 'invalid_exact_evm_payload_authorization_valid_after',
      (int) ($auth['validBefore'] ?? 0) < $now + self::MARGIN => 'invalid_exact_evm_payload_authorization_valid_before',
      default => NULL,
    };
  }

  /**
   * The transferWithAuthorization call, from the buyer's payload.
   *
   * @throws \Drupal\x402_pay\InvalidPaymentException
   *   Missing fields, or not a 65-byte signature.
   */
  public static function calldata(array $payload): string {
    $auth = $payload['payload']['authorization'] ?? [];
    $signature = (string) ($payload['payload']['signature'] ?? '');
    // ponytail: EOA signatures only (r ‖ s ‖ v); smart wallets (ERC-1271)
    // need the bytes overload of transferWithAuthorization.
    if (!preg_match('/^0x[0-9a-fA-F]{130}$/', $signature)) {
      throw new InvalidPaymentException('The signature is not 65 bytes.');
    }
    $v = hexdec(substr($signature, 130, 2));
    $words = [];
    foreach (['from', 'to', 'value', 'validAfter', 'validBefore', 'nonce'] as $field) {
      $value = (string) ($auth[$field] ?? throw new InvalidPaymentException("The authorization has no $field."));
      $words[] = in_array($field, ['value', 'validAfter', 'validBefore'], TRUE) && !str_starts_with($value, '0x')
        ? Rpc::word(gmp_strval(gmp_init($value, 10), 16))
        : Rpc::word($value);
    }
    $words[] = Rpc::word(dechex($v < 27 ? $v + 27 : $v));
    $words[] = substr($signature, 2, 64);
    $words[] = substr($signature, 66, 64);
    return self::TRANSFER_WITH_AUTHORIZATION . implode('', $words);
  }

  /**
   * The key that pays the gas.
   *
   * @throws \Drupal\x402_pay\FacilitatorException
   *   No key, or not an EVM private key.
   */
  private function gasWallet(): Signer {
    try {
      return new Signer(trim((string) $this->keys->getKey((string) ($this->settings()['own_key_id'] ?? ''))?->getKeyValue()));
    }
    catch (\InvalidArgumentException) {
      throw new FacilitatorException('No gas wallet key for our own facilitator.');
    }
  }

  /**
   * The network's node.
   */
  private function rpc(string $network): Rpc {
    return new Rpc($this->httpClient, Networks::ALL[$network]['rpc'] ?? throw new FacilitatorException("Unsupported network: $network."));
  }

  /**
   * The facilitator settings.
   */
  private function settings(): array {
    return $this->config->get('x402_pay.settings')->get('facilitator') ?? [];
  }

}
