<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('in_stock')) {
            $normalized = filter_var($this->input('in_stock'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $this->merge(['in_stock' => $normalized]);
        }

        if (is_string($this->input('slugs'))) {
            $slugs = collect(explode(',', $this->input('slugs')))
                ->map(fn (string $slug) => trim($slug))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $this->merge(['slugs' => $slugs]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:255'],
            'price_min' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'price_max' => ['sometimes', 'numeric', 'min:0', 'max:9999999999', 'gte:price_min'],
            'rating' => ['sometimes', 'numeric', 'between:0,5'],
            'in_stock' => ['sometimes', 'boolean'],
            'slugs' => ['sometimes', 'array', 'min:1', 'max:100'],
            'slugs.*' => ['string', 'max:255', 'distinct'],
            'sort' => ['sometimes', Rule::in(['newest', 'price_asc', 'price_desc', 'top_rated', 'best_selling'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
