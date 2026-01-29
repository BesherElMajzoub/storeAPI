<?php

namespace App\Http\Requests\Api\V1\Auth;

class ForgotPasswordRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
