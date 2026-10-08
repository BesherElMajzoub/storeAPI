<?php

namespace App\Http\Resources;

use App\Models\ContactMessageReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContactMessageReply */
class ContactMessageReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'admin_name' => $this->whenLoaded('admin', fn () => $this->admin?->name) ?? config('mail.brand.name'),
            'body' => $this->body,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
