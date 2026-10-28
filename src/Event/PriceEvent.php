<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Event;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Quebracho\X402\Networks;
use Quebracho\X402\Signer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Before charging: the price, the seller and what the buyer gets.
 *
 * Change the price, make it free, or deny it; choose who is paid and say
 * what the buyer gets.
 *
 * Dispatched on every sale, with the whole context: the entity (already in
 * the request's language), what is sold (the view mode), who asks and how.
 * Starts at the field's price, free for those with "access x402 content
 * free". The field says what is sold; this says what it costs right now.
 */
final class PriceEvent extends Event {

  /**
   * Whether the sale was refused.
   */
  private bool $denied = FALSE;

  /**
   * The wallet paid, or NULL for the field's key.
   */
  private ?Signer $seller = NULL;

  /**
   * The address paid when no key here signs for it.
   */
  private ?string $payTo = NULL;

  /**
   * What the buyer gets, as a MIME type.
   */
  private string $mimeType = 'text/markdown';

  /**
   * How long the buyer's payment stays valid, in seconds.
   */
  private int $timeout = Networks::TIMEOUT;

  /**
   * The network sold on, or NULL for the field's.
   */
  private ?string $network = NULL;

  /**
   * Whether the payment settles before the delivery (x402's `upfront`).
   */
  private bool $settleFirst = FALSE;

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

  /**
   * Charges this price in dollars, e.g. "0.01", even if it was free.
   */
  public function setPrice(string $usd): void {
    $this->price = $usd;
    $this->free = FALSE;
  }

  /**
   * Whether this sale costs nothing.
   */
  public function isFree(): bool {
    return $this->free;
  }

  /**
   * Delivers without charging.
   */
  public function makeFree(): void {
    $this->free = TRUE;
  }

  /**
   * Whether this sale was refused.
   */
  public function isDenied(): bool {
    return $this->denied;
  }

  /**
   * Refuses the sale: the buyer gets a 403; later listeners are skipped.
   */
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
    $this->payTo = NULL;
  }

  /**
   * The seller's address alone, when not the field's.
   *
   * A wallet whose key is not on this site. Needs a Circle API key (see
   * CircleFacilitator).
   */
  public function setPayTo(string $address): void {
    // Base58 (Solana) is kept as is; checksumming is EVM's.
    $this->payTo = X402PriceItem::isSolanaAddress($address) ? $address : Signer::checksum($address);
    $this->seller = NULL;
  }

  /**
   * The key of the wallet paid.
   *
   * NULL for the field's, or for an address set alone.
   */
  public function seller(): ?Signer {
    return $this->seller;
  }

  /**
   * The address paid for this sale, or NULL for the field's.
   */
  public function payTo(): ?string {
    return $this->payTo ?? $this->seller?->address();
  }

  /**
   * Says what the buyer gets, announced in the 402.
   *
   * Markdown unless delivered otherwise (see DeliveryEvent).
   */
  public function setMimeType(string $mimeType): void {
    $this->mimeType = $mimeType;
  }

  /**
   * What the buyer gets, as a MIME type.
   */
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

  /**
   * How long the buyer's payment stays valid, in seconds.
   */
  public function timeout(): int {
    return $this->timeout;
  }

  /**
   * Sells on another network than the field's: one of Networks::all().
   *
   * On Solana, pay an address (setPayTo()): no key here settles there.
   *
   * @throws \InvalidArgumentException
   *   A network Networks does not list.
   */
  public function setNetwork(string $network): void {
    $this->network = isset(Networks::all()[$network]) ? $network : throw new \InvalidArgumentException("Unknown network $network.");
  }

  /**
   * The network sold on, or NULL for the field's.
   */
  public function network(): ?string {
    return $this->network;
  }

  /**
   * Settles the payment first, then delivers: x402's `upfront` flow.
   *
   * For a delivery that outlives the buyer's payment, e.g. a slow product
   * on Solana, whose payment dies with its blockhash (~60–90 s) whatever
   * the timeout says. The buyer pays even if the delivery then fails: check
   * the input before (a 4xx at the door costs nothing), and refund by hand
   * what the log shows settled with a failed delivery.
   */
  public function settleFirst(): void {
    $this->settleFirst = TRUE;
  }

  /**
   * Whether the payment settles before the delivery.
   */
  public function settlesFirst(): bool {
    return $this->settleFirst;
  }

}
