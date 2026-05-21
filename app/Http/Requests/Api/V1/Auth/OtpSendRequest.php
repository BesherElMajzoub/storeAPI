<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Validation\Rule;

class OtpSendRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'purpose' => ['sometimes', 'string', Rule::in(['password_reset', 'email_verification'])],
            'channel' => ['sometimes', 'string', Rule::in(['email'])],
        ];
    }
}
