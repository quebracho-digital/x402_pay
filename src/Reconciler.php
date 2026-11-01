<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Quebracho\X402\Networks;
use Quebracho\X402\Rpc;

/**
 * Checks the payment log against the chain.
 *
 * A settlement sent but not mined in time is logged failed with its
 * transaction (settlement_pending): the buyer got nothing, but the transfer
 * may still land. Each cron asks for its receipt and records what happened.
 * Once a week every settled sale of the last days is checked for its
 * transfer on chain: the payTo and the amount the log says.
 *
 * EVM only. RPC nodes stop indexing old transactions (about two weeks on
 * Arc's), so only recent rows are checked.
 */
class Reconciler {

  /**
   * Transfer(address,address,uint256).
   */
  private const TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

  /**
   * A pending transaction still without a receipt after this is dropped.
   */
  private const DROPPED_AFTER = 86400;

  /**
   * Seconds between checks of the settled sales.
   */
  private const WEEK = 604800;

  /**
   * The error a settled sale gets when the buyer did not get the body.
   */
  public const REFUND = 'delivery failed after settling: refund';

  public function __construct(
    private readonly Connection $database,
    private readonly ClientInterface $httpClient,
    private readonly HttpFactory $psr17,
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Settles pending rows; checks the settled ones when a week has passed.
   */
  public function cron(): void {
    $this->pending();
    $now = $this->time->getRequestTime();
    if ($now - (int) $this->state->get('x402_pay.reconciled', 0) >= self::WEEK) {
      $this->state->set('x402_pay.reconciled', $now);
      $this->settled(8);
    }
  }

  /**
   * Records the outcome of each settlement still pending.
   *
   * @return list<array{id: int, transaction: string, outcome: string}>
   *   One row per pending sale the chain answered for: settled, reverted,
   *   dropped or pending.
   */
  public function pending(): array {
    $rows = $this->database->select('x402_pay_log', 'l')
      ->fields('l', ['id', 'created', 'network', 'transaction'])
      ->condition('status', 'failed')
      ->condition('error', 'settlement_pending')
      ->isNotNull('transaction')
      ->condition('network', 'eip155:%', 'LIKE')
      ->execute()->fetchAll();
    $done = [];
    foreach ($rows as $row) {
      $context = ['@id' => $row->id, '@tx' => $row->transaction];
      try {
        $receipt = $this->receipt($row->network, $row->transaction);
      }
      catch (\RuntimeException | \InvalidArgumentException $e) {
        $this->logger->warning('Payment @id (@tx): no answer from the chain, next cron. @message', $context + ['@message' => $e->getMessage()]);
        continue;
      }
      [$outcome, $fields] = match (TRUE) {
        // Paid, and the buyer was refused the body: owed a refund.
        ($receipt['status'] ?? NULL) === '0x1' => ['settled', ['status' => 'settled', 'error' => self::REFUND]],
        is_array($receipt) => ['reverted', ['error' => 'transaction_reverted']],
        $this->time->getRequestTime() - $row->created > self::DROPPED_AFTER => [
          'dropped',
          ['error' => 'settlement dropped: never mined'],
        ],
        default => ['pending', []],
      };
      if ($fields) {
        $this->database->update('x402_pay_log')->fields($fields)->condition('id', $row->id)->execute();
        $this->logger->notice('Payment @id (@tx): settlement_pending is now @outcome.', $context + ['@outcome' => $outcome]);
      }
      $done[] = ['id' => (int) $row->id, 'transaction' => $row->transaction, 'outcome' => $outcome];
    }
    return $done;
  }

  /**
   * Checks that each settled sale of the last days moved its amount on chain.
   *
   * @param int $days
   *   How far back.
   *
   * @return list<array{id: int, transaction: string, problem: ?string}>
   *   One row per sale checked; problem NULL when the chain agrees.
   */
  public function settled(int $days): array {
    $rows = $this->database->select('x402_pay_log', 'l')
      ->fields('l', ['id', 'network', 'transaction', 'pay_to', 'amount'])
      ->condition('status', 'settled')
      ->isNotNull('transaction')
      ->condition('network', 'eip155:%', 'LIKE')
      ->condition('created', $this->time->getRequestTime() - $days * 86400, '>=')
      ->execute()->fetchAll();
    $checked = [];
    foreach ($rows as $row) {
      try {
        $receipt = $this->receipt($row->network, $row->transaction);
      }
      catch (\RuntimeException | \InvalidArgumentException $e) {
        $problem = 'no answer from the chain: ' . $e->getMessage();
        $checked[] = ['id' => (int) $row->id, 'transaction' => $row->transaction, 'problem' => $problem];
        continue;
      }
      $problem = match (TRUE) {
        $receipt === NULL => 'no receipt on chain',
        ($receipt['status'] ?? NULL) !== '0x1' => 'transaction reverted',
        !$this->pays($receipt, $row->pay_to, (string) $row->amount) => 'no transfer of the amount to payTo',
        default => NULL,
      };
      if ($problem !== NULL) {
        $this->logger->error('Payment @id (@tx) logged settled: @problem.', [
          '@id' => $row->id,
          '@tx' => $row->transaction,
          '@problem' => $problem,
        ]);
      }
      $checked[] = ['id' => (int) $row->id, 'transaction' => $row->transaction, 'problem' => $problem];
    }
    return $checked;
  }

  /**
   * Whether the receipt holds a Transfer of $amount to $payTo.
   */
  private function pays(array $receipt, string $payTo, string $amount): bool {
    $to = '0x' . str_pad(strtolower(substr($payTo, 2)), 64, '0', STR_PAD_LEFT);
    foreach ($receipt['logs'] ?? [] as $log) {
      if (($log['topics'][0] ?? '') === self::TRANSFER && strtolower($log['topics'][2] ?? '') === $to
        && gmp_cmp(Rpc::number((string) $log['data']), gmp_init($amount, 10)) === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The transaction's receipt, or NULL while it has none.
   *
   * @throws \RuntimeException
   *   The node is unreachable.
   * @throws \InvalidArgumentException
   *   A network the library does not know.
   */
  private function receipt(string $network, string $hash): ?array {
    $rpc = new Rpc($this->httpClient, $this->psr17, $this->psr17, Networks::get($network)['rpc']);
    $receipt = $rpc('eth_getTransactionReceipt', [$hash]);
    return is_array($receipt) ? $receipt : NULL;
  }

}
