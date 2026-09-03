<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductsRequest extends FormRequest
{
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
            'sort' => ['sometimes', Rule::in(['newest', 'price_asc', 'price_desc', 'top_rated', 'best_selling'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
