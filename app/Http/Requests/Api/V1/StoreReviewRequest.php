<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by Policy in the controller
    }

    public function rules(): array
    {
        return [
            'rating'  => 'required|integer|between:1,5',
            'comment' => 'nullable|string|min:10|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'rating.required'     => 'A rating is required.',
            'rating.between'      => 'Rating must be between 1 and 5.',
            'comment.min'         => 'Comment must be at least 10 characters.',
            'comment.max'         => 'Comment cannot exceed 2000 characters.',
        ];
    }
}
