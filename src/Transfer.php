<?php

/**
 * @file
 * The pre-library Transfer, now the one in quebracho/x402.
 */

declare(strict_types=1);

namespace Drupal\x402_pay;

use Quebracho\X402\Transfer as LibraryTransfer;

@trigger_error('Drupal\x402_pay\Transfer is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use \Quebracho\X402\Transfer instead. See https://www.drupal.org/project/x402_pay', E_USER_DEPRECATED);

class_alias(LibraryTransfer::class, Transfer::class);
