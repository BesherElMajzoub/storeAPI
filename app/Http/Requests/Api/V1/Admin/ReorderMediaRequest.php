<?php

namespace App\Http\Requests\Api\V1\Admin;

class ReorderMediaRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'order'   => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'order.required' => 'An ordered array of media IDs is required.',
            'order.*.integer' => 'Each item in order must be a valid media ID integer.',
        ];
    }
}
