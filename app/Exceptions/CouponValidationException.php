<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class CouponValidationException extends Exception
{
    /**
     * Render the exception as an HTTP response.
     */
    public function render($request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'data'    => null,
            'errors'  => [
                'code' => [$this->getMessage()]
            ],
        ], 422);
    }
}
