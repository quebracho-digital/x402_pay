<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Event;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A payment's outcome, once it is in the log.
 *
 * React to it: ECA, accounting, reputation, a notice to the seller.
 *
 * Statuses: settled (paid and delivered), not_settled (the handler failed,
 * nothing charged), failed (delivered but settlement failed: body withheld).
 */
final class PaymentEvent extends Event {

  public function __construct(
    // Keys: status, resource, network, amount (atomic units), pay_to,
    // payer, transaction (when settled), handler_status, error.
    public readonly array $entry,
    public readonly Request $request,
  ) {}

  /**
   * The outcome: settled, not_settled or failed.
   */
  public function status(): string {
    return $this->entry['status'];
  }

}
