<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

class ListProductsRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'is_featured' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in([
                'created_desc', 'price_asc', 'price_desc', 'stock_asc',
                'name_asc',
            ])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
