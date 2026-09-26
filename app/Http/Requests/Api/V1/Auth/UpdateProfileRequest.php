<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Validation\Rule;

class UpdateProfileRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
                'regex:/^\+?[0-9][0-9\s().-]{6,29}$/',
                Rule::unique('users', 'phone')->ignore($userId),
            ],
        ];
    }
}
