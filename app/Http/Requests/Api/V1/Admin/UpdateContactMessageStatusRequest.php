<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContactMessageStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Covered by admin middleware
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:new,read,replied,archived'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
