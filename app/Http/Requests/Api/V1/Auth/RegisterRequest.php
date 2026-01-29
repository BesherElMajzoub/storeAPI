<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Validation\Rule;

class RegisterRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('users', 'phone'),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
