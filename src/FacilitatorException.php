<?php

/**
 * @file
 * The pre-library FacilitatorException, now the one in quebracho/x402.
 */

declare(strict_types=1);

namespace Drupal\x402_pay;

use Quebracho\X402\FacilitatorException as LibraryFacilitatorException;

@trigger_error('Drupal\x402_pay\FacilitatorException is deprecated in x402_pay:1.0.0-beta3 and is removed from x402_pay:2.0.0. Use \Quebracho\X402\FacilitatorException instead. See https://www.drupal.org/project/x402_pay/issues/3634901', E_USER_DEPRECATED);

class_alias(LibraryFacilitatorException::class, 'Drupal\x402_pay\FacilitatorException');
