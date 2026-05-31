<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'                  => 'required|array',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.variant_id'     => 'nullable|exists:product_variants,id',
            'items.*.quantity'       => 'required|integer|min:1',
            'shipping_address'       => 'required|array',
            'shipping_address.name'  => 'required|string',
            'shipping_address.line1' => 'required|string',
            'shipping_address.city'  => 'required|string',
            'shipping_address.country' => 'required|string',
            'coupon_code'            => 'nullable|string',
            'shipping_rate_id'       => 'nullable|string',
            'easypost_shipment_id'   => 'nullable|string',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'data'    => null,
            'errors'  => $validator->errors(),
        ], 422));
    }
}
