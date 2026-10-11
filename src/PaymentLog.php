<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

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
  ) {}

  /**
   * Records one sale attempt.
   *
   * @param array $entry
   *   Keys: resource, network, amount, pay_to, payer, status, transaction,
   *   handler_status, error.
   */
  public function record(array $entry): void {
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
    ])->execute();
  }

}
