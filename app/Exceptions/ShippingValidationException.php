<?php

namespace App\Exceptions;

use RuntimeException;

class ShippingValidationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'invalid_shipping')
    {
        parent::__construct($message);
    }
}
