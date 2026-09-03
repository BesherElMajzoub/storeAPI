<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'confirmed'],
        ];
    }
}
