<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

/**
 * The facilitator could not be reached or refused the call.
 *
 * The message carries Circle's reason code when there is one, e.g.
 * registration_required once the keyless trial is used up.
 */
final class FacilitatorException extends \RuntimeException {}
