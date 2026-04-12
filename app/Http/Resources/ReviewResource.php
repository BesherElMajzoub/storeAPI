<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id'                   => $this->id,
            'rating'               => (int) $this->rating,
            'comment'              => $this->comment,
            'is_approved'          => (bool) $this->is_approved,
            'is_verified_purchase' => (bool) $this->is_verified_purchase,
            // Only show if it's the reviewer's own review (for "my review" context)
            'is_own_review'        => $user?->id === $this->user_id,
            'created_at'           => $this->created_at?->toIso8601String(),
            'updated_at'           => $this->updated_at?->toIso8601String(),
            'user'                 => $this->whenLoaded('user', fn() => [
                'id'         => $this->user?->id,
                'name'       => $this->user?->name,
                'avatar_url' => $this->user?->avatar_url,
            ]),
            // Only included in admin context
            'admin_note'           => $this->when(
                $user?->hasRole('Admin'),
                $this->admin_note
            ),
        ];
    }
}
