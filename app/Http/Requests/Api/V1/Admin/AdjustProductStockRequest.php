<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

class AdjustProductStockRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'delta' => ['required', 'integer', 'between:-1000000,1000000', Rule::notIn([0])],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ];
    }
}
