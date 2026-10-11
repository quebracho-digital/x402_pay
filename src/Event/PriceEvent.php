<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Event;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Drupal\x402_pay\Networks;
use Drupal\x402_pay\Signer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Before charging: change the price, make it free, or deny it; choose who
 * is paid and say what the buyer gets.
 *
 * Dispatched on every sale, with the whole context: the entity (already in
 * the request's language), what is sold (the view mode), who asks and how.
 * Starts at the field's price, free for those with "access x402 content
 * free". The field says what is sold; this says what it costs right now.
 */
final class PriceEvent extends Event {

  private bool $denied = FALSE;

  private ?Signer $seller = NULL;

  private string $mimeType = 'text/markdown';

  private int $timeout = Networks::TIMEOUT;

  public function __construct(
    public readonly FieldableEntityInterface $entity,
    public readonly X402PriceItem $item,
    public readonly string $viewMode,
    public readonly Request $request,
    public readonly AccountInterface $account,
    private string $price,
    private bool $free,
  ) {}

  /**
   * The price in dollars, e.g. "0.01".
   */
  public function price(): string {
    return $this->price;
  }

  public function setPrice(string $usd): void {
    $this->price = $usd;
    $this->free = FALSE;
  }

  public function isFree(): bool {
    return $this->free;
  }

  public function makeFree(): void {
    $this->free = TRUE;
  }

  public function isDenied(): bool {
    return $this->denied;
  }

  public function deny(): void {
    $this->denied = TRUE;
    $this->stopPropagation();
  }

  /**
   * The seller's key, when not the field's: one seller per entity.
   *
   * The money goes to this key's address; it signs the facilitator's proof.
   */
  public function setSeller(Signer $seller): void {
    $this->seller = $seller;
  }

  public function seller(): ?Signer {
    return $this->seller;
  }

  /**
   * What the buyer gets, announced in the 402: markdown unless delivered
   * otherwise (see DeliveryEvent).
   */
  public function setMimeType(string $mimeType): void {
    $this->mimeType = $mimeType;
  }

  public function mimeType(): string {
    return $this->mimeType;
  }

  /**
   * How long the buyer's payment stays valid, in seconds.
   *
   * Settling happens after the delivery, so a slow product (a model reading
   * a long document) needs more than the default, or the buyer's signature
   * expires while the seller works and the sale fails.
   */
  public function setTimeout(int $seconds): void {
    $this->timeout = $seconds;
  }

  public function timeout(): int {
    return $this->timeout;
  }

}
