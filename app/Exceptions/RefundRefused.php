<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Stripe (or the bank) refused a refund for good. Retrying the same request
 * cannot help, so the queue job gives up instead of repeating it.
 */
class RefundRefused extends RuntimeException
{
    public function __construct(
        public readonly ?string $failureCode,
        string $message,
        public readonly string $nextAction,
    ) {
        parent::__construct($message);
    }
}
