<?php

/**
 * @file
 * The pre-library Solana, now the one in quebracho/x402.
 */

declare(strict_types=1);

namespace Drupal\x402_pay;

use Quebracho\X402\Solana as LibrarySolana;

@trigger_error('Drupal\x402_pay\Solana is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use \Quebracho\X402\Solana instead. See https://www.drupal.org/project/x402_pay/issues/3634901', E_USER_DEPRECATED);

// Solana::pay() is gone: build the payment with
// \Quebracho\X402\SolanaExact::pay(), or Buyer::pay() to buy a whole call.
class_alias(LibrarySolana::class, 'Drupal\x402_pay\Solana');
