<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Quebracho\X402\Networks as LibraryNetworks;

@trigger_error('Drupal\x402_pay\Networks is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use \Quebracho\X402\Networks instead. See https://www.drupal.org/project/x402_pay/issues/3634901', E_USER_DEPRECATED);

/**
 * The chains and tokens a price can be charged in, as of 1.0.0-beta2.
 *
 * @deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use
 *   \Quebracho\X402\Networks instead: Networks::find($network) in place of
 *   Networks::ALL[$network]. It knows these networks once the module is
 *   loaded (ModuleNetworks).
 * @see https://www.drupal.org/project/x402_pay/issues/3634901
 */
final class Networks {

  /**
   * CAIP-2 network => its USDC, as 1.0.0-beta2 listed them.
   *
   * A constant cannot ask the library, so the list is kept here as it was.
   */
  public const ALL = [
    'eip155:5042002' => [
      'label' => 'Arc testnet (USDC)',
      'asset' => '0x3600000000000000000000000000000000000000',
      'decimals' => 6,
      'extra' => ['name' => 'USDC', 'version' => '2'],
      'rpc' => 'https://rpc.testnet.arc.network',
      'explorer' => 'https://testnet.arcscan.app/tx/',
    ],
    'eip155:5042' => [
      'label' => 'Arc (USDC)',
      'asset' => '0x3600000000000000000000000000000000000000',
      'decimals' => 6,
      'extra' => ['name' => 'USDC', 'version' => '2'],
      'rpc' => 'https://rpc.mainnet.arc.io',
      'explorer' => 'https://explorer.arc.io/tx/',
    ],
    // cspell:disable-next-line
    'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1' => [
      'label' => 'Solana devnet (USDC)',
      // cspell:disable-next-line
      'asset' => '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU',
      'decimals' => 6,
      'extra' => [],
      'rpc' => 'https://api.devnet.solana.com',
      'explorer' => 'https://explorer.solana.com/tx/',
    ],
    // cspell:disable-next-line
    'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp' => [
      'label' => 'Solana (USDC)',
      // cspell:disable-next-line
      'asset' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
      'decimals' => 6,
      'extra' => [],
      'rpc' => 'https://api.mainnet-beta.solana.com',
      'explorer' => 'https://solscan.io/tx/',
    ],
  ];

  /**
   * How long the buyer's signed payment stays valid, in seconds, by default.
   */
  public const TIMEOUT = LibraryNetworks::TIMEOUT;

  /**
   * One PaymentRequirements for a price in dollars.
   *
   * @throws \InvalidArgumentException
   *   Unknown network, or a price the token cannot represent.
   */
  public static function requirements(string $network, string $usd, string $payTo, int $timeout = self::TIMEOUT): array {
    return LibraryNetworks::requirements($network, $usd, $payTo, $timeout);
  }

  /**
   * A positive decimal dollar amount in the token's atomic units, exactly.
   *
   * @throws \InvalidArgumentException
   */
  public static function atomic(string $usd, int $decimals): string {
    return LibraryNetworks::atomic($usd, $decimals);
  }

}
