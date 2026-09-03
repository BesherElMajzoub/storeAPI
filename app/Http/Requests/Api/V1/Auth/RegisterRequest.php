<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

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
                'regex:/^\+?[0-9][0-9\s().-]{6,29}$/',
                Rule::unique('users', 'phone'),
            ],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'confirmed'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
