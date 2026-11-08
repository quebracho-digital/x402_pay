<?php

declare(strict_types=1);

namespace Drupal\x402_pay\EventSubscriber;

use Quebracho\X402\AddressRejected;
use Quebracho\X402\BudgetReclaim;
use Quebracho\X402\PaymentFault;
use Quebracho\X402\PaymentIntent;
use Quebracho\X402\PaymentLog;
use Quebracho\X402\PaymentOutcome;
use Quebracho\X402\PurchaseIntent;
use Quebracho\X402\PurchaseOutcome;
use Quebracho\X402\ScreeningSkipped;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Writes the events of quebracho/x402 to the x402_pay log channel.
 *
 * The library reports what it swallowed or decided on its own (a sanctioned
 * payer refused, a ledger that failed while the sale went on, a screening
 * that could not answer) as events, and logs nothing itself. The library's
 * PaymentLog turns each into a line; this subscriber hands it the events
 * the site's dispatcher receives, so they reach Drupal's log (dblog, syslog,
 * whatever the site routes).
 */
final class LibraryEventLogger implements EventSubscriberInterface {

  public function __construct(private readonly PaymentLog $log) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // One entry per event class the library logs: PaymentLog::events() maps
    // each to the method of the library's PaymentLog that writes it, and the
    // methods below keep those names.
    return array_map(fn(string $method) => [$method], PaymentLog::events());
  }

  /**
   * Logs a payment step about to run.
   */
  public function intent(PaymentIntent $event): void {
    $this->log->intent($event);
  }

  /**
   * Logs how a payment ended.
   */
  public function outcome(PaymentOutcome $event): void {
    $this->log->outcome($event);
  }

  /**
   * Logs a failure of the payment state.
   */
  public function fault(PaymentFault $event): void {
    $this->log->fault($event);
  }

  /**
   * Logs an address a screening list refused.
   */
  public function rejected(AddressRejected $event): void {
    $this->log->rejected($event);
  }

  /**
   * Logs an address that passed because a list could not answer.
   */
  public function skipped(ScreeningSkipped $event): void {
    $this->log->skipped($event);
  }

  /**
   * Logs a purchase about to be paid.
   */
  public function purchaseIntent(PurchaseIntent $event): void {
    $this->log->purchaseIntent($event);
  }

  /**
   * Logs how a purchase ended.
   */
  public function purchase(PurchaseOutcome $event): void {
    $this->log->purchase($event);
  }

  /**
   * Logs the budget reserved for a payment that never left.
   */
  public function reclaim(BudgetReclaim $event): void {
    $this->log->reclaim($event);
  }

}
