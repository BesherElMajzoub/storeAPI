<?php

namespace App\Http\Requests\Api\V1\Admin;

class UploadMediaRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        // Support both single `image` (category) and multiple `images[]` (product)
        return [
            'images'   => ['sometimes', 'array', 'min:1'],
            'images.*' => ['required_without:image', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image'    => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
