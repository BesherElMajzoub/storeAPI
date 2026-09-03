<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1|max:100',
            'shipping_address' => 'required|array',
            'shipping_address.name' => 'required|string|max:255',
            'shipping_address.line1' => 'required|string|max:255',
            'shipping_address.line2' => 'nullable|string|max:255',
            'shipping_address.city' => 'required|string|max:100',
            'shipping_address.state' => 'required|string|max:100',
            'shipping_address.postal_code' => 'required|string|max:20',
            'shipping_address.country' => 'required|string|size:2',
            'shipping_address.phone' => 'nullable|string|max:50',
            'billing_address' => 'sometimes|nullable|array',
            'coupon_code' => 'nullable|string|max:64|regex:/^[A-Za-z0-9_-]+$/',
            'shipping_rate_id' => 'required|string|max:255',
            'easypost_shipment_id' => 'nullable|string|max:255',
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
