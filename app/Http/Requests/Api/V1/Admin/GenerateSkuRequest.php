<?php

namespace App\Http\Requests\Api\V1\Admin;

class GenerateSkuRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:product,variant'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'product_name' => ['required', 'string', 'max:255'],
            'product_slug' => ['nullable', 'string', 'max:255'],
            'variant_name' => ['nullable', 'required_if:type,variant', 'string', 'max:255'],
        ];
    }
}
