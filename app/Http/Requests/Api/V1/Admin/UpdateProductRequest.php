<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Product;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        $productId = (int) $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'discount_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($productId)],
            'stock_qty' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'category_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'options' => ['sometimes', 'nullable', 'array'],
            'in_stock' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'rating' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'reviews_count' => ['sometimes', 'integer', 'min:0'],
            'images' => ['sometimes', 'array'],
            'images.*' => ['string', 'max:2048'],
            'variants' => ['sometimes', 'array'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:255'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock_qty' => ['nullable', 'integer', 'min:0'],
            'variants.*.attributes' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->has('discount_price')) {
                return;
            }

            $discount = $this->input('discount_price');
            if ($discount === null) {
                return;
            }

            $price = $this->input('price');
            if ($price === null) {
                $product = Product::find($this->route('id'));
                $price = $product?->price;
            }

            if ($price !== null && $discount >= $price) {
                $validator->errors()->add('discount_price', 'The discount price must be less than price.');
            }
        });
    }
}
