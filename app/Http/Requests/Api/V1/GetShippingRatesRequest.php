<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GetShippingRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address'            => ['required', 'array'],
            'address.name'       => ['required', 'string', 'max:255'],
            'address.street1'    => ['required', 'string', 'max:255'],
            'address.street2'    => ['nullable', 'string', 'max:255'],
            'address.city'       => ['required', 'string', 'max:255'],
            'address.state'      => ['required', 'string', 'max:255'],
            'address.zip'        => ['required', 'string', 'max:20'],
            'address.country'    => ['required', 'string', 'size:2'], // 2-letter ISO code
            'address.phone'      => ['nullable', 'string', 'max:50'],
            
            'parcel'             => ['nullable', 'array'],
            'parcel.length'      => ['nullable', 'numeric', 'min:0.1'],
            'parcel.width'       => ['nullable', 'numeric', 'min:0.1'],
            'parcel.height'      => ['nullable', 'numeric', 'min:0.1'],
            'parcel.weight'      => ['nullable', 'numeric', 'min:0.1'], // in ounces
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
