<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is done in the controller via Policy
    }

    public function rules(): array
    {
        return [
            'label'       => 'sometimes|required|in:home,work,other',
            'full_name'   => 'sometimes|required|string|max:255',
            'phone'       => 'sometimes|required|string|max:20',
            'country'     => 'sometimes|required|string|max:100',
            'city'        => 'sometimes|required|string|max:100',
            'area'        => 'nullable|string|max:100',
            'street'      => 'sometimes|required|string|max:255',
            'building'    => 'nullable|string|max:100',
            'floor'       => 'nullable|string|max:50',
            'apartment'   => 'nullable|string|max:50',
            'postal_code' => 'nullable|string|max:20',
            'notes'       => 'nullable|string|max:500',
            'is_default'  => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'label.in' => 'Label must be one of: home, work, other.',
        ];
    }
}
