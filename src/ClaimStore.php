<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Each payment buys one delivery: the library's claim, kept in the database.
 *
 * The library asks `fn(string $key): bool` before it runs the handler: TRUE
 * the first time a payment is seen, FALSE after. A unique key makes that
 * atomic and durable across processes, restarts and hosts, where the old
 * Drupal lock only held while one request ran.
 */
class ClaimStore {

  /**
   * How long a claim is kept, in seconds.
   *
   * A payment cannot settle past its validBefore, which the library caps
   * near the offer's maxTimeoutSeconds (10 minutes at most).
   */
  private const KEEP = 86400;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Claims a payment: TRUE the first time, FALSE if it was claimed before.
   *
   * A database that cannot record it cannot prove it is fresh: refuse.
   */
  public function __invoke(string $key): bool {
    try {
      $this->database->insert('x402_pay_claim')->fields([
        'claim_key' => $key,
        'created' => $this->time->getRequestTime(),
      ])->execute();
      return TRUE;
    }
    catch (\Exception) {
      // Taken (a unique key violation) or not recorded: either way, no.
      return FALSE;
    }
  }

  /**
   * Frees a claim whose payment was not used: nothing delivered or settled.
   */
  public function release(string $key): void {
    $this->database->delete('x402_pay_claim')->condition('claim_key', $key)->execute();
  }

  /**
   * Forgets the claims that can no longer matter.
   */
  public function prune(): void {
    $this->database->delete('x402_pay_claim')
      ->condition('created', $this->time->getRequestTime() - self::KEEP, '<')
      ->execute();
  }

}
