<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\x402_pay\Buyer;
use Drupal\x402_pay\PaymentHeaders;
use Drupal\x402_pay\Solana;
use Drupal\x402_pay\SolanaKey;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

// cspell:disable
/**
 * A buyer's Solana payment: the accounts, the transaction, the signature.
 */
#[Group('x402_pay')]
final class SolanaTest extends UnitTestCase {

  private const USDC = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';

  /**
   * What a 402 on Solana mainnet asks.
   */
  private const REQUIREMENTS = [
    'scheme' => 'exact',
    'network' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
    'amount' => '10000',
    'asset' => self::USDC,
    'payTo' => 'DcQNaByvstRZCERvb9UJHgHiQe8YX2FMdPptM86psQyz',
    'maxTimeoutSeconds' => 60,
    'extra' => [
      'feePayer' => 'CKPKJWNdJEqa81x7CkZ14BVPiY6y16Sxs7owznqtWYp5',
      'recentBlockhash' => 'EZ3rST5dvHmbanh75jc4PuLfV96vp9fEYBVeNk4FfM1k',
    ],
  ];

  /**
   * Associated token accounts, as mainnet holds them (2026-10-21).
   */
  public function testAssociatedTokenAccounts(): void {
    $this->assertSame('7eoSSCvNpRi9r1tuta95n3ycUthyCZ5dYRjFqosmWT13', Solana::ata('DcQNaByvstRZCERvb9UJHgHiQe8YX2FMdPptM86psQyz', self::USDC));
    $this->assertSame('EqU5zLz9apQpMvgkgK7oANDtCGtn83vBgMwKmrWaUNGL', Solana::ata('BqRWCWor1ZqdnKPzuQXB6TdqDp1bzpkEx5UDasMM2KSu', self::USDC));
    // A wallet's key is on the curve; a program address never is.
    $this->assertTrue(Solana::onCurve(Solana::decode('DcQNaByvstRZCERvb9UJHgHiQe8YX2FMdPptM86psQyz')));
    $this->assertFalse(Solana::onCurve(Solana::decode('7eoSSCvNpRi9r1tuta95n3ycUthyCZ5dYRjFqosmWT13')));
  }

  /**
   * The key reads as solana-keygen and Phantom export it.
   */
  public function testKeyFormats(): void {
    $pair = sodium_crypto_sign_seed_keypair(str_repeat("\1", 32));
    $secret = sodium_crypto_sign_secretkey($pair);
    $address = Solana::encode(sodium_crypto_sign_publickey($pair));
    $this->assertSame($address, (new SolanaKey(json_encode(array_map('ord', str_split($secret)))))->address());
    $this->assertSame($address, (new SolanaKey(Solana::encode($secret)))->address());
    $this->assertSame($address, (new SolanaKey(Solana::encode(str_repeat("\1", 32))))->address());
    $this->expectException(\InvalidArgumentException::class);
    new SolanaKey('0x1234');
  }

  /**
   * The buyer pays the 402 with one transfer the facilitator can sponsor.
   */
  public function testBuyerPaysWithOneTransactionToSponsor(): void {
    $history = [];
    $required = PaymentHeaders::encode([
      'x402Version' => 2,
      'resource' => ['url' => 'https://example.com/x'],
      'accepts' => [['network' => 'eip155:5042'] + self::REQUIREMENTS, self::REQUIREMENTS],
    ]);
    $stack = HandlerStack::create(new MockHandler([
      new Response(402, [PaymentHeaders::REQUIRED => $required]),
      new Response(200, [PaymentHeaders::RESPONSE => PaymentHeaders::encode(['success' => TRUE, 'transaction' => 'sig'])], '{"ok":true}'),
    ]));
    $stack->push(Middleware::history($history));
    $key = new SolanaKey(Solana::encode(str_repeat("\1", 32)));
    $paid = (new Buyer(new Client(['handler' => $stack]), $key))->pay('https://example.com/x', ['q' => 1]);
    $this->assertSame([200, 'sig'], [$paid['status'], $paid['tx']]);
    // The Solana offer, not the first one; the blockhash it suggested: no
    // node asked.
    $this->assertCount(2, $history);
    $payment = PaymentHeaders::decode($history[1]['request']->getHeaderLine(PaymentHeaders::SIGNATURE));
    $this->assertSame(self::REQUIREMENTS['network'], $payment['accepted']['network']);

    $wire = base64_decode($payment['payload']['transaction']);
    // Two signatures: the fee payer's left empty, then the buyer's.
    $this->assertSame("\x02" . str_repeat("\0", 64), substr($wire, 0, 65));
    $message = substr($wire, 129);
    $this->assertTrue(sodium_crypto_sign_verify_detached(substr($wire, 65, 64), $message, Solana::decode($key->address())));

    // v0; two signers, the buyer read-only; four read-only accounts.
    $this->assertSame("\x80\x02\x01\x04\x08", substr($message, 0, 5));
    $keys = array_map(Solana::encode(...), str_split(substr($message, 5, 8 * 32), 32));
    $this->assertSame([
      self::REQUIREMENTS['extra']['feePayer'],
      $key->address(),
      Solana::ata($key->address(), self::USDC),
      '7eoSSCvNpRi9r1tuta95n3ycUthyCZ5dYRjFqosmWT13',
      self::USDC,
      Solana::TOKEN_PROGRAM,
      Solana::COMPUTE_BUDGET,
      Solana::MEMO,
    ], $keys);
    $this->assertSame(self::REQUIREMENTS['extra']['recentBlockhash'], Solana::encode(substr($message, 5 + 256, 32)));
    // Compute limit, compute price, the transfer (exact amount, 6
    // decimals), a memo; the fee payer (index 0) in none of them.
    $instructions = substr($message, 5 + 256 + 32);
    $transfer = "\x05\x04\x02\x04\x03\x01\x0a\x0c" . pack('P', 10000) . "\x06";
    $this->assertSame("\x04\x06\x00\x05\x02" . pack('V', Solana::COMPUTE_UNITS) . "\x06\x00\x09\x03" . pack('P', 1) . $transfer . "\x07\x00\x20", substr($instructions, 0, 41));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\x00$/', substr($instructions, 41));
  }

}
