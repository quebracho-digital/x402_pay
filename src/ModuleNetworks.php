<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

use Quebracho\X402\Networks;

/**
 * The mainnets this module sells on, added to the library's testnets.
 *
 * quebracho/x402 never moves real money by default: it ships testnets only
 * and leaves a mainnet to the caller (Networks::add, "whether the chain is a
 * testnet is the caller's responsibility"). A site that sells for real opts
 * in by installing this module, so the choice is made here, once.
 */
final class ModuleNetworks {

  /**
   * Whether register() already ran in this process.
   */
  private static bool $done = FALSE;

  /**
   * Adds Arc and Solana mainnet to the library's catalogue. Idempotent.
   */
  public static function register(): void {
    if (self::$done) {
      return;
    }
    self::$done = TRUE;
    Networks::add(
      chainId: 5042,
      asset: '0x3600000000000000000000000000000000000000',
      name: 'USDC',
      version: '2',
      label: 'Arc (USDC)',
      rpc: 'https://rpc.mainnet.arc.io',
      explorer: 'https://explorer.arc.io/tx/',
    );
    // LIBRARY-FEEDBACK G1: there is no way to add a non-EVM network.
    // cspell:disable-next-line
    Networks::addSolana(
      // cspell:disable-next-line
      genesis: '5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
      // cspell:disable-next-line
      mint: 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
      label: 'Solana (USDC)',
      rpc: 'https://api.mainnet-beta.solana.com',
      explorer: 'https://solscan.io/tx/',
    );
  }

}
