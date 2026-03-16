<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by sanctum middleware
    }

    public function rules(): array
    {
        return [
            'label'       => 'required|in:home,work,other',
            'full_name'   => 'required|string|max:255',
            'phone'       => 'required|string|max:20',
            'country'     => 'required|string|max:100',
            'city'        => 'required|string|max:100',
            'area'        => 'nullable|string|max:100',
            'street'      => 'required|string|max:255',
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
            'label.in'     => 'Label must be one of: home, work, other.',
            'full_name.required' => 'Full name is required.',
            'phone.required'     => 'Phone number is required.',
            'country.required'   => 'Country is required.',
            'city.required'      => 'City is required.',
            'street.required'    => 'Street address is required.',
        ];
    }
}
