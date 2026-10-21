<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

/**
 * A buyer's x402 payment on Solana: one USDC transfer, built and signed.
 *
 * The transaction the `exact` scheme's facilitators take on their fast
 * path: compute limit, compute price, TransferChecked to the seller's
 * associated token account, a memo that makes it unique. The facilitator's
 * fee payer comes first and signs last: it pays the fees and appears in no
 * instruction.
 *
 * @see https://github.com/coinbase/x402/blob/main/specs/schemes/exact/scheme_exact_svm.md
 */
final class Solana {

  /**
   * Bitcoin's base58 alphabet, which Solana uses.
   */
  // cspell:disable-next-line
  public const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

  /**
   * The programs a payment calls: tokens, token accounts, compute, memo.
   */
  // cspell:disable-next-line
  public const TOKEN_PROGRAM = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';
  // cspell:disable-next-line
  public const ATA_PROGRAM = 'ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL';
  public const COMPUTE_BUDGET = 'ComputeBudget111111111111111111111111111111';
  // cspell:disable-next-line
  public const MEMO = 'MemoSq4gqABAXKb96qnH8TysNcWxMyWCqXgDLGmfcHr';

  /**
   * Compute units asked for: a transfer and a memo use under 15k.
   */
  public const COMPUTE_UNITS = 40000;

  /**
   * Bytes from base58.
   *
   * @throws \InvalidArgumentException
   *   A character outside the alphabet.
   */
  public static function decode(string $text): string {
    if ($text === '' || strspn($text, self::ALPHABET) !== strlen($text)) {
      throw new \InvalidArgumentException('Not base58.');
    }
    $n = gmp_init(0);
    foreach (str_split($text) as $c) {
      $n = $n * 58 + strpos(self::ALPHABET, $c);
    }
    return str_repeat("\0", strspn($text, '1')) . (gmp_sign($n) ? gmp_export($n) : '');
  }

  /**
   * Base58 from bytes.
   */
  public static function encode(string $bytes): string {
    $n = $bytes === '' ? gmp_init(0) : gmp_import($bytes);
    $out = '';
    while (gmp_sign($n) > 0) {
      [$n, $r] = gmp_div_qr($n, 58);
      $out = self::ALPHABET[gmp_intval($r)] . $out;
    }
    return str_repeat('1', strspn($bytes, "\0")) . $out;
  }

  /**
   * Whether 32 bytes are a point of ed25519, as Solana decides it.
   *
   * A compressed point is y (little-endian, the top bit is x's sign); it is
   * on the curve when x² = (y² − 1) / (d·y² + 1) has a root mod p.
   */
  public static function onCurve(string $point): bool {
    $p = gmp_sub(gmp_pow(2, 255), 19);
    $d = gmp_mod(gmp_mul(-121665, gmp_invert(121666, $p)), $p);
    $y = gmp_mod(gmp_import(strrev($point & str_repeat("\xff", 31) . "\x7f")), $p);
    $y2 = gmp_mod(gmp_mul($y, $y), $p);
    $x2 = gmp_mod(gmp_mul(gmp_sub($y2, 1), gmp_invert(gmp_add(gmp_mul($d, $y2), 1), $p)), $p);
    // Euler's criterion: a square, or zero.
    return gmp_sign($x2) === 0 || gmp_cmp(gmp_powm($x2, gmp_div_q(gmp_sub($p, 1), 2), $p), 1) === 0;
  }

  /**
   * The associated token account of an owner for a mint (base58).
   */
  public static function ata(string $owner, string $mint): string {
    $seeds = self::decode($owner) . self::decode(self::TOKEN_PROGRAM) . self::decode($mint);
    // The program address: the first bump down from 255 that is off-curve.
    for ($bump = 255; $bump >= 0; $bump--) {
      $address = hash('sha256', $seeds . chr($bump) . self::decode(self::ATA_PROGRAM) . 'ProgramDerivedAddress', TRUE);
      if (!self::onCurve($address)) {
        return self::encode($address);
      }
    }
    throw new \RuntimeException('No program address for these seeds.');
  }

  /**
   * The v0 message that pays the requirements, to be signed.
   *
   * @param array $requirements
   *   One x402 PaymentRequirements on Solana, with extra.feePayer.
   * @param string $buyer
   *   The buyer's address: source of the transfer, and its authority.
   * @param string $blockhash
   *   A recent blockhash (base58): the payment is valid ~60–90 s from it.
   * @param int $decimals
   *   The mint's decimals, which TransferChecked repeats.
   * @param string $memo
   *   The seller's extra.memo when it set one, otherwise a random nonce.
   */
  public static function message(array $requirements, string $buyer, string $blockhash, int $decimals, string $memo): string {
    $feePayer = $requirements['extra']['feePayer'] ?? throw new \InvalidArgumentException('The requirements name no fee payer.');
    $mint = $requirements['asset'];
    // Signers first (fee payer writable, buyer read-only), then writable
    // accounts, then read-only ones and the programs.
    $keys = [
      $feePayer, $buyer,
      self::ata($buyer, $mint), self::ata($requirements['payTo'], $mint),
      $mint, self::TOKEN_PROGRAM, self::COMPUTE_BUDGET, self::MEMO,
    ];
    $instructions = [
      // SetComputeUnitLimit, SetComputeUnitPrice (1 microlamport per unit).
      [6, [], "\x02" . pack('V', self::COMPUTE_UNITS)],
      [6, [], "\x03" . pack('P', 1)],
      // TransferChecked: source, mint, destination, authority.
      [5, [2, 4, 3, 1], "\x0c" . pack('P', (int) $requirements['amount']) . chr($decimals)],
      [7, [], $memo],
    ];
    // Version 0; two signers, one of them read-only; four read-only keys.
    $message = "\x80\x02\x01\x04" . self::length(count($keys)) . implode('', array_map(self::decode(...), $keys)) . self::decode($blockhash);
    $message .= self::length(count($instructions));
    foreach ($instructions as [$program, $accounts, $data]) {
      $message .= chr($program) . self::length(count($accounts)) . implode('', array_map('chr', $accounts)) . self::length(strlen($data)) . $data;
    }
    // No address lookup tables.
    return $message . self::length(0);
  }

  /**
   * The transaction, signed by the buyer, as x402's payload carries it.
   *
   * @return string
   *   Base64 of the wire transaction; the fee payer's signature still
   *   empty, for the facilitator to add.
   */
  public static function transaction(string $message, SolanaKey $buyer): string {
    return base64_encode(self::length(2) . str_repeat("\0", 64) . $buyer->sign($message) . $message);
  }

  /**
   * Solana's compact-u16 length (shortvec).
   */
  private static function length(int $n): string {
    $out = '';
    do {
      $byte = $n & 0x7f;
      $n >>= 7;
      $out .= chr($byte | ($n ? 0x80 : 0));
    } while ($n);
    return $out;
  }

}
