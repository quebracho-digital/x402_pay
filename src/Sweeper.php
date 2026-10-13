<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Moves what the seller wallets collected to a cold wallet.
 *
 * Keyless selling keeps each seller's key on this server, and with it the
 * money. Sweeping bounds the loss if the server is compromised to what
 * arrived since the last sweep. Each wallet pays its own gas, which on Arc
 * is the same USDC: what moves is the balance less the most the gas can
 * cost.
 */
class Sweeper {

  /**
   * Native USDC on Arc has 18 decimals; the token's interface, 6.
   */
  private const NATIVE_PER_ATOMIC = '1000000000000';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $config,
    private readonly KeyRepositoryInterface $keys,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Sweeps when it is on and the interval has passed.
   */
  public function cron(): void {
    $settings = $this->config->get('x402_pay.settings')->get('sweep') ?? [];
    $last = (int) $this->state->get('x402_pay.sweep_last', 0);
    if (empty($settings['enabled']) || $this->time->getRequestTime() - $last < (int) ($settings['interval'] ?? 21600)) {
      return;
    }
    $this->state->set('x402_pay.sweep_last', $this->time->getRequestTime());
    $this->sweep();
  }

  /**
   * Sweeps every configured key on every network.
   *
   * @param bool $send
   *   FALSE: only say what would move.
   *
   * @return list<array{from: string, network: string, balance: string, amount: string, transaction: ?string, error: ?string}>
   *   One row per wallet and network with a balance or an error; amount 0
   *   when below the minimum.
   */
  public function sweep(bool $send = TRUE): array {
    $settings = $this->config->get('x402_pay.settings')->get('sweep') ?? [];
    $to = (string) ($settings['to'] ?? '');
    if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $to)) {
      throw new \RuntimeException('No cold wallet to sweep to.');
    }
    $min = Networks::atomic((string) ($settings['min'] ?? '1'), 6);
    $rows = [];
    foreach ($settings['keys'] ?? [] as $id) {
      try {
        $from = new Signer(trim((string) $this->keys->getKey($id)?->getKeyValue()));
      }
      catch (\InvalidArgumentException) {
        $this->logger->error('Sweep: the key @id is not an EVM private key.', ['@id' => $id]);
        continue;
      }
      // ponytail: every network listed is Arc, whose gas is USDC; a chain
      // with another gas token needs that token on each wallet first.
      foreach (Networks::ALL as $network => $token) {
        $row = $this->one($from, $network, $token, $to, $min, $send);
        if ($row['balance'] !== '0' || $row['error']) {
          $rows[] = $row;
        }
      }
    }
    return $rows;
  }

  /**
   * Sweeps one wallet on one network.
   */
  private function one(Signer $from, string $network, array $token, string $to, string $min, bool $send): array {
    $row = [
      'from' => $from->address(),
      'network' => $network,
      'balance' => '0',
      'amount' => '0',
      'transaction' => NULL,
      'error' => NULL,
    ];
    try {
      $rpc = new Rpc($this->httpClient, $token['rpc']);
      $balanceOf = ['to' => $token['asset'], 'data' => '0x70a08231' . Rpc::word($from->address())];
      $balance = Rpc::number($rpc('eth_call', [$balanceOf, 'latest']));
      $row['balance'] = gmp_strval($balance);
      if (gmp_sign($balance) === 0) {
        return $row;
      }
      $gasPrice = Rpc::number($rpc('eth_gasPrice', []));
      // Twice today's price, so a busier block still takes it.
      $maxFee = gmp_mul($gasPrice, 2);
      $transfer = [
        'from' => $from->address(),
        'to' => $token['asset'],
        'data' => '0xa9059cbb' . Rpc::word($to) . Rpc::word(gmp_strval($balance, 16)),
      ];
      // Its estimate and a fifth more.
      $gas = gmp_div_q(gmp_mul(Rpc::number($rpc('eth_estimateGas', [$transfer])), 12), 10);
      // The most the gas can cost, in token units, rounded up.
      $fee = gmp_div_q(gmp_mul($gas, $maxFee), self::NATIVE_PER_ATOMIC, GMP_ROUND_PLUSINF);
      $amount = gmp_sub($balance, $fee);
      if (gmp_cmp($amount, gmp_init($min)) < 0) {
        return $row;
      }
      $row['amount'] = gmp_strval($amount);
      if (!$send) {
        return $row;
      }
      $raw = Transfer::sign($from, [
        'chainId' => explode(':', $network)[1],
        'nonce' => gmp_strval(Rpc::number($rpc('eth_getTransactionCount', [$from->address(), 'pending']))),
        'maxPriorityFeePerGas' => gmp_strval($gasPrice),
        'maxFeePerGas' => gmp_strval($maxFee),
        'gas' => gmp_strval($gas),
        'token' => $token['asset'],
        'to' => $to,
        'amount' => gmp_strval($amount),
      ]);
      $row['transaction'] = $rpc('eth_sendRawTransaction', [$raw]);
      $this->logger->notice('Swept @amount (atomic) from @from to @to on @network: @tx', [
        '@amount' => $row['amount'],
        '@from' => $row['from'],
        '@to' => $to,
        '@network' => $network,
        '@tx' => $row['transaction'],
      ]);
    }
    catch (\Throwable $e) {
      $row['error'] = $e->getMessage();
      $this->logger->error('Sweep of @from on @network failed: @message', [
        '@from' => $row['from'],
        '@network' => $network,
        '@message' => $e->getMessage(),
      ]);
    }
    return $row;
  }

}
