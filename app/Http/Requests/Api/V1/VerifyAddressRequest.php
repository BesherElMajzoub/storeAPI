<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifyAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:255'],
            'street1'    => ['required', 'string', 'max:255'],
            'street2'    => ['nullable', 'string', 'max:255'],
            'city'       => ['required', 'string', 'max:255'],
            'state'      => ['required', 'string', 'max:255'],
            'zip'        => ['required', 'string', 'max:20'],
            'country'    => ['required', 'string', 'size:2'], // 2-letter ISO code
            'phone'      => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'data'    => null,
            'errors'  => $validator->errors(),
        ], 422));
    }
}
