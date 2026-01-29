<?php

namespace App\Http\Requests\Api\V1\Auth;

class ResetPasswordRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
