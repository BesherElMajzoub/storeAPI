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
            'address' => ['required', 'array'],
            'address.name' => ['nullable', 'string', 'max:255'],
            'address.line1' => ['required_without:address.street1', 'string', 'max:255'],
            'address.street1' => ['required_without:address.line1', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.street2' => ['nullable', 'string', 'max:255'],
            'address.city' => ['required', 'string', 'max:255'],
            'address.state' => ['required', 'string', 'max:255'],
            'address.postal_code' => ['required_without:address.zip', 'string', 'max:20'],
            'address.zip' => ['required_without:address.postal_code', 'string', 'max:20'],
            'address.country' => ['required', 'string', 'size:2'], // 2-letter ISO code
            'address.phone' => ['nullable', 'string', 'max:50'],

            'items' => ['required_without:parcel', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],

            'parcel' => ['required_without:items', 'array'],
            'parcel.length' => ['required_with:parcel', 'numeric', 'between:0.1,200'],
            'parcel.width' => ['required_with:parcel', 'numeric', 'between:0.1,200'],
            'parcel.height' => ['required_with:parcel', 'numeric', 'between:0.1,200'],
            'parcel.weight' => ['required_with:parcel', 'numeric', 'between:0.1,2400'], // in ounces
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'data' => null,
            'errors' => $validator->errors(),
        ], 422));
    }
}
