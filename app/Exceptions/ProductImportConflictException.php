<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ProductImportConflictException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The catalog changed during import; run the preview again.', 0, $previous);
    }
}
