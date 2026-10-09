<?php

declare(strict_types=1);

namespace Drupal\x402_pay;

/**
 * A payment header that cannot be read: answered with a 400.
 */
final class InvalidPaymentException extends \InvalidArgumentException {}
