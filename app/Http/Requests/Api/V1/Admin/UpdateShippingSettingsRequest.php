<?php

namespace App\Http\Requests\Api\V1\Admin;

class UpdateShippingSettingsRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'free_shipping_enabled' => ['required', 'boolean'],
            'free_shipping_threshold' => ['nullable', 'required_if:free_shipping_enabled,true', 'numeric', 'min:0'],
        ];
    }
}
