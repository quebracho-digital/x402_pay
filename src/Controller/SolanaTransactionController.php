<?php

declare(strict_types=1);

namespace Drupal\x402_pay\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\x402_pay\Networks;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Drupal\x402_pay\Solana;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * A Solana payment for a wallet in the browser to sign.
 *
 * At /x402/solana/transaction: POST {accepted, buyer}, one entry of a 402's
 * accepts and the buyer's address. The answer is the transaction that pays
 * it, unsigned, as Buyer builds it for a key on the server: the wallet
 * signs it (solana:signTransaction) and the page sends it back as the
 * payload. Built here, so the browser needs no Solana library.
 */
final class SolanaTransactionController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * Transactions per visitor per minute: each asks the node a blockhash.
   */
  private const LIMIT = 30;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * The unsigned transaction; 400 when it is not ours to build.
   */
  public function __invoke(Request $request): JsonResponse {
    if (!$this->flood->isAllowed('x402_pay.solana_transaction', self::LIMIT)) {
      return new JsonResponse(['error' => 'Too many transactions: wait a minute.'], 429);
    }
    $this->flood->register('x402_pay.solana_transaction');
    $body = json_decode($request->getContent(), TRUE);
    $req = $body['accepted'] ?? NULL;
    $buyer = $body['buyer'] ?? NULL;
    $network = Networks::ALL[$req['network'] ?? ''] ?? NULL;
    // Only what this site would ask: a Solana network it knows, its USDC,
    // addresses and an amount; anything else is for no 402 of ours.
    $addresses = [$buyer, $req['payTo'] ?? NULL, $req['extra']['feePayer'] ?? NULL];
    if (!str_starts_with($req['network'] ?? '', 'solana:') || !$network || ($req['asset'] ?? NULL) !== $network['asset']
      || !ctype_digit((string) ($req['amount'] ?? '')) || array_filter($addresses, fn($a) => !is_string($a) || !X402PriceItem::isSolanaAddress($a))) {
      return new JsonResponse(['error' => 'Not a Solana payment this site asks for.'], 400);
    }
    try {
      return new JsonResponse(['transaction' => Solana::pay($this->httpClient, $req, $buyer)]);
    }
    catch (\RuntimeException | GuzzleException $e) {
      return new JsonResponse(['error' => 'The Solana node did not answer: ' . $e->getMessage()], 502);
    }
  }

}
