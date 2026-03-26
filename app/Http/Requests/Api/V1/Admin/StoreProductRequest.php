<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

class StoreProductRequest extends BaseAdminRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['in_stock', 'is_featured', 'is_active'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $val = strtolower($this->input($field));
                if ($val === 'true' || $val === '1') {
                    $merge[$field] = true;
                } elseif ($val === 'false' || $val === '0') {
                    $merge[$field] = false;
                }
            }
        }

        foreach (['variants', 'options'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $decoded = json_decode($this->input($field), true);
                if (is_array($decoded)) {
                    $merge[$field] = $decoded;
                }
            }
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'discount_price' => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('products', 'sku')],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'options' => ['nullable', 'array'],
            'in_stock' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:1000'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'reviews_count' => ['nullable', 'integer', 'min:0'],
            'images' => ['sometimes', 'array'],
            'images.*' => ['file', 'image', 'max:5120'], // max 5 MB per image
            'variants' => ['sometimes', 'array'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:255', Rule::unique('product_variants', 'sku')],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock_qty' => ['nullable', 'integer', 'min:0'],
            'variants.*.attributes' => ['nullable', 'array'],
        ];
    }
}
