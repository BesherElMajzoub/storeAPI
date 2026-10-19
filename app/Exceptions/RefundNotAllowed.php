<?php

namespace App\Exceptions;

use RuntimeException;

/** A refund request the safeguards refuse; carries the API error code and HTTP status. */
class RefundNotAllowed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toErrors(): array
    {
        return ['code' => $this->errorCode] + $this->errors;
    }
}
