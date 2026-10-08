<?php

namespace App\Http\Requests\Api\V1\Admin;

class StoreContactMessageReplyRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }
}
