<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'confirmed'],
        ];
    }
}
