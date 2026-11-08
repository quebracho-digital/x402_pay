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
use Quebracho\X402\Settlement;

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
   * The error of a transaction that never got mined.
   */
  private const DROPPED = 'settlement dropped: never mined';

  /**
   * How far back failed rows are looked at (nodes forget older ones).
   */
  private const WINDOW = 8 * 86400;

  /**
   * AuthorizationUsed(address,bytes32): the token's own proof of a spent authorization.
   */
  private const AUTHORIZATION_USED = '0x98de503528ee59b575ef0c0a2576a82497bfc029a5685b209e9ec333479b10a5';

  /**
   * Seconds between checks of the settled sales.
   */
  private const WEEK = 604800;

  /**
   * The error a settled sale gets when the buyer did not get the body.
   */
  public const REFUND = 'delivery failed after settling: refund';

  /**
   * The error of a sale whose logged transaction failed but whose authorization is spent.
   *
   * Not called settled: cancelling an authorization spends it too.
   */
  public const USED = 'authorization_used: paid by another transaction? check by hand';

  /**
   * The selector of authorizationState(address,bytes32).
   */
  private const AUTHORIZATION_STATE = '0xe94a0102';

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
   * Records the outcome of each failed settlement that may still have paid.
   *
   * Examined: the failures that may have moved money (a transaction sent but
   * not mined, a facilitator that timed out after sending, a reverted one),
   * for as long as the nodes remember them. A row is not final while its
   * authorization can be spent: anyone holding the signed payload can submit
   * it, and then the buyer paid even though the logged transaction failed.
   *
   * @return list<array{id: int, transaction: string, outcome: string}>
   *   One row per sale looked at: settled, reverted, dropped,
   *   authorization_used, pending or no_answer (the node did not say).
   */
  public function pending(): array {
    $rows = $this->database->select('x402_pay_log', 'l')
      ->fields('l', ['id', 'created', 'network', 'transaction', 'payer', 'nonce', 'valid_before', 'error'])
      ->condition('status', 'failed')
      ->condition('error', [...Settlement::MAY_HAVE_MOVED, 'transaction_reverted', self::DROPPED], 'IN')
      ->condition('network', 'eip155:%', 'LIKE')
      ->condition('created', $this->time->getRequestTime() - self::WINDOW, '>=')
      ->execute()->fetchAll();
    $done = [];
    foreach ($rows as $row) {
      $transaction = (string) $row->transaction;
      $context = ['@id' => $row->id, '@tx' => $transaction];
      if ($transaction === '' && !$row->nonce) {
        // Nothing to ask the chain about.
        continue;
      }
      try {
        $receipt = $transaction === '' ? NULL : $this->receipt($row->network, $transaction);
        $spent = ($receipt['status'] ?? NULL) === '0x1' ? FALSE : $this->spent($row);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Payment @id (@tx): no answer from the chain, next cron. @message', $context + ['@message' => $e->getMessage()]);
        $done[] = ['id' => (int) $row->id, 'transaction' => $transaction, 'outcome' => 'no_answer'];
        continue;
      }
      [$outcome, $fields] = match (TRUE) {
        // Paid, and the buyer was refused the body: owed a refund.
        ($receipt['status'] ?? NULL) === '0x1' => ['settled', ['status' => 'settled', 'error' => self::REFUND]],
        // Reverted or lost, yet the authorization is spent: it paid some
        // other way. Not called settled: cancelling spends it too.
        $spent => ['authorization_used', ['error' => self::USED]],
        // Anyone holding the signed payload can still submit it: no verdict.
        $row->valid_before && $this->time->getRequestTime() <= (int) $row->valid_before => ['pending', []],
        is_array($receipt) => ['reverted', ['error' => 'transaction_reverted']],
        $transaction === '' => ['pending', []],
        $this->time->getRequestTime() - $row->created > self::DROPPED_AFTER => ['dropped', ['error' => self::DROPPED]],
        default => ['pending', []],
      };
      if ($fields && ($fields['error'] !== $row->error || isset($fields['status']))) {
        $this->database->update('x402_pay_log')->fields($fields)->condition('id', $row->id)->execute();
        $level = $outcome === 'authorization_used' ? 'error' : 'notice';
        $this->logger->$level('Payment @id (@tx): @was is now @outcome.', $context + [
          '@was' => $row->error,
          '@outcome' => $outcome,
        ]);
      }
      $done[] = ['id' => (int) $row->id, 'transaction' => $transaction, 'outcome' => $outcome];
    }
    return $done;
  }

  /**
   * Checks that each settled sale of the last days moved its amount on chain.
   *
   * A sale is paid when its own signed authorization (the payer and nonce the
   * log took from the signature) was spent in the transaction it cites, by
   * the network's token, for the amount and to payTo. The transaction and
   * the payer in the log are what the facilitator reported: the chain is
   * asked about the signature instead.
   *
   * @param int $days
   *   How far back.
   *
   * @return list<array{id: int, transaction: string, problem: ?string}>
   *   One row per sale checked; problem NULL when the chain agrees.
   */
  public function settled(int $days): array {
    $rows = $this->database->select('x402_pay_log', 'l')
      ->fields('l', ['id', 'network', 'transaction', 'pay_to', 'amount', 'payer', 'nonce'])
      ->condition('status', 'settled')
      ->isNotNull('transaction')
      ->condition('network', 'eip155:%', 'LIKE')
      ->condition('created', $this->time->getRequestTime() - $days * 86400, '>=')
      ->execute()->fetchAll();
    $cited = [];
    foreach ($rows as $row) {
      $key = $row->network . strtolower($row->transaction);
      $cited[$key] = ($cited[$key] ?? 0) + 1;
    }
    $checked = [];
    foreach ($rows as $row) {
      try {
        $receipt = $this->receipt($row->network, $row->transaction);
        $problem = match (TRUE) {
          $receipt === NULL => 'no receipt on chain',
          ($receipt['status'] ?? NULL) !== '0x1' => 'transaction reverted',
          $cited[$row->network . strtolower($row->transaction)] > 1 => 'transaction cited by another sale',
          default => $this->pays($receipt, $row),
        };
      }
      catch (\Throwable $e) {
        $problem = 'no answer from the chain: ' . $e->getMessage();
      }
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
   * How many rows are waiting for a person: refunds owed, authorizations used.
   *
   * @param int $days
   *   How far back.
   */
  public function standing(int $days): int {
    return (int) $this->database->select('x402_pay_log', 'l')
      ->condition('error', [self::REFUND, self::USED], 'IN')
      ->condition('created', $this->time->getRequestTime() - $days * 86400, '>=')
      ->countQuery()->execute()->fetchField();
  }

  /**
   * Whether the token says the sale's authorization was spent.
   *
   * @return bool
   *   FALSE when the log has no nonce or payer (nothing to ask).
   *
   * @throws \Throwable
   *   The node did not answer, or answered something that is not a bool: the
   *   caller keeps the row for the next run instead of deciding on a guess.
   */
  private function spent(object $row): bool {
    if (!preg_match('/^0x[0-9a-f]{64}$/', (string) $row->nonce) || !preg_match('/^0x[0-9a-fA-F]{40}$/', (string) $row->payer)) {
      return FALSE;
    }
    $network = Networks::get($row->network);
    $rpc = new Rpc($this->httpClient, $this->psr17, $this->psr17, $network['rpc']);
    $data = self::AUTHORIZATION_STATE . str_pad(strtolower(substr($row->payer, 2)), 64, '0', STR_PAD_LEFT) . substr($row->nonce, 2);
    $answer = $rpc('eth_call', [['to' => $network['asset'], 'data' => $data], 'latest']);
    if (!is_string($answer) || !preg_match('/^0x[0-9a-fA-F]+$/', $answer)) {
      throw new \RuntimeException('authorizationState answered ' . json_encode($answer));
    }
    return gmp_cmp(Rpc::number($answer), 0) !== 0;
  }

  /**
   * Why the receipt does not hold the sale's payment, or NULL when it does.
   *
   * A Transfer of the amount to payTo, by the network's token, and (when the
   * log has the signed payer and nonce) the token's AuthorizationUsed event for
   * that payer and nonce in the same receipt. Any contract can emit a
   * Transfer-shaped event; only the token emits AuthorizationUsed, and only
   * for an authorization that was signed.
   */
  private function pays(array $receipt, object $row): ?string {
    $asset = strtolower(Networks::get($row->network)['asset']);
    $pad = fn(string $address): string => '0x' . str_pad(strtolower(substr($address, 2)), 64, '0', STR_PAD_LEFT);
    $legacy = $row->nonce === NULL || $row->nonce === '';
    if (!$legacy && !preg_match('/^0x[0-9a-fA-F]{40}$/', (string) $row->payer)) {
      return 'unverifiable: the log has no payer';
    }
    $paid = FALSE;
    $spent = $legacy;
    foreach ($receipt['logs'] ?? [] as $log) {
      if (strtolower((string) ($log['address'] ?? '')) !== $asset) {
        continue;
      }
      $topics = array_map('strtolower', array_map('strval', $log['topics'] ?? []));
      if (($topics[0] ?? '') === self::TRANSFER && ($topics[2] ?? '') === $pad($row->pay_to)
        && ($legacy ? ($row->payer === NULL || $row->payer === '' || ($topics[1] ?? '') === $pad($row->payer)) : TRUE)
        && preg_match('/^0x[0-9a-fA-F]+$/', (string) $log['data'])
        && gmp_cmp(Rpc::number((string) $log['data']), gmp_init($row->amount, 10)) === 0) {
        $paid = TRUE;
      }
      if (!$legacy && ($topics[0] ?? '') === self::AUTHORIZATION_USED
        && ($topics[1] ?? '') === $pad($row->payer) && ($topics[2] ?? '') === strtolower($row->nonce)) {
        $spent = TRUE;
      }
    }
    return match (TRUE) {
      !$paid => 'no transfer of the amount to payTo',
      !$spent => 'authorization not spent in this transaction',
      default => NULL,
    };
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
