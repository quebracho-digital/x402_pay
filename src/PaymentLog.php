<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Every paid request: what was bought, by whom, and what happened.
 *
 * Statuses: settled (paid and delivered), not_settled (the handler failed,
 * nothing charged), failed (delivered but settlement failed: body withheld).
 */
class PaymentLog {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ?LoggerInterface $logger = NULL,
  ) {}

  /**
   * Records one sale attempt.
   *
   * @param array $entry
   *   Keys: resource, network, amount, pay_to, payer, status, transaction,
   *   handler_status, error; nonce, the authorization's; entity_type and
   *   entity_id, what was sold.
   */
  public function record(array $entry): void {
    try {
      $this->insert($entry);
    }
    catch (\Exception $e) {
      // Called after the money moved: a database failure must not turn a
      // paid sale into a 500. The line keeps what the reconciler would need.
      $this->logger?->error('Payment not logged (@error): @status @amount to @payTo on @network, payer @payer, transaction @tx, nonce @nonce.', [
        '@error' => $e->getMessage(),
        '@status' => $entry['status'] ?? '',
        '@amount' => $entry['amount'] ?? '',
        '@payTo' => $entry['pay_to'] ?? '',
        '@network' => $entry['network'] ?? '',
        '@payer' => $entry['payer'] ?? '',
        '@tx' => $entry['transaction'] ?? '',
        '@nonce' => $entry['nonce'] ?? '',
      ]);
    }
  }

  /**
   * Inserts the row.
   */
  private function insert(array $entry): void {
    $this->database->insert('x402_pay_log')->fields([
      'created' => $this->time->getRequestTime(),
      'resource' => mb_substr($entry['resource'], 0, 2048),
      'network' => $entry['network'],
      'amount' => $entry['amount'],
      'pay_to' => $entry['pay_to'],
      'payer' => $entry['payer'],
      'status' => $entry['status'],
      'transaction' => $entry['transaction'],
      'handler_status' => $entry['handler_status'],
      'error' => $entry['error'] ?? NULL,
      'nonce' => $entry['nonce'] ?? NULL,
      'valid_before' => $entry['valid_before'] ?? NULL,
      'entity_type' => $entry['entity_type'] ?? NULL,
      'entity_id' => $entry['entity_id'] ?? NULL,
    ])->execute();
  }

}
