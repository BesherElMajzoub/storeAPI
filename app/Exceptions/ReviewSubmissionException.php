<?php

namespace App\Exceptions;

use RuntimeException;

class ReviewSubmissionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
