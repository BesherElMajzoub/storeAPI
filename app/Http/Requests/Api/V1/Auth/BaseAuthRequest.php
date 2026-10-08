<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class BaseAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        $response = response()->json([
            'success' => false,
            // The first field error reads better in a form than a generic line.
            'message' => $validator->errors()->first() ?: 'Validation failed.',
            'data' => null,
            'errors' => $validator->errors(),
        ], 422);

        throw new HttpResponseException($response);
    }
}
