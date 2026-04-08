<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Validation\Rule;

class UpdateProfileRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'name'     => ['sometimes', 'string', 'max:255'],
            'email'    => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone'    => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
                Rule::unique('users', 'phone')->ignore($userId),
            ],
            'avatar'   => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'password' => ['sometimes', 'string', 'min:8', 'confirmed'],
        ];
    }
}
