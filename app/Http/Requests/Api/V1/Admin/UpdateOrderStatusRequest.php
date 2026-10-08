<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in([
                'pending',
                'processing',
                'shipped',
                'delivered',
                'cancelled',
                'refunded',
            ])],
            'payment_status' => ['sometimes', Rule::in([
                'unpaid',
                'failed',
            ])],
            // A parcel shipped without a purchased label (e.g. at the counter).
            'tracking_number' => ['sometimes', 'string', 'max:64', 'prohibited_unless:status,shipped', 'required_with:shipping_carrier'],
            'shipping_carrier' => ['sometimes', 'string', 'max:64', 'prohibited_unless:status,shipped', 'required_with:tracking_number'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->has('status') && ! $this->has('payment_status')) {
                $validator->errors()->add('status', 'Provide status and/or payment_status.');
            }
        });
    }
}
