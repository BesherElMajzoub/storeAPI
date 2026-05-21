<?php

namespace App\Http\Requests\Api\V1\Admin;

class RejectCancellationRequestRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'admin_note' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
