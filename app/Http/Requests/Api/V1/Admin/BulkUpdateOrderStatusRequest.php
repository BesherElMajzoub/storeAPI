<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

class BulkUpdateOrderStatusRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', Rule::exists('orders', 'id')->whereNull('deleted_at')],
            'status' => ['required', Rule::in([
                'pending',
                'pending_payment',
                'processing',
                'shipped',
                'delivered',
                'cancelled',
                'refunded',
            ])],
        ];
    }
}
