<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

class BulkUpdateProductsRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'set' => ['required', 'array:status,in_stock,is_featured,category_id'],
            'set.status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'set.in_stock' => ['sometimes', 'boolean'],
            'set.is_featured' => ['sometimes', 'boolean'],
            'set.category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (is_array($this->input('set')) && count($this->input('set')) === 0) {
                $validator->errors()->add('set', 'At least one supported field is required.');
            }
        });
    }
}
