<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        // Define status label
        $statusLabel = 'active';
        if (!$this->is_active) {
            $statusLabel = 'inactive';
        } elseif ($this->is_expired) {
            $statusLabel = 'expired';
        } elseif (!$this->is_started) {
            $statusLabel = 'scheduled';
        }

        return [
            'id'                      => $this->id,
            'code'                    => $this->code,
            'type'                    => $this->type,
            'value'                   => $this->value,
            'minimum_order_amount'    => $this->minimum_order_amount,
            'maximum_discount_amount' => $this->maximum_discount_amount,
            'usage_limit'             => $this->usage_limit,
            'used_count'              => $this->used_count,
            'remaining_uses'          => $this->remaining_uses,
            'usage_limit_per_user'    => $this->usage_limit_per_user,
            'starts_at'               => $this->starts_at?->toIso8601String(),
            'expires_at'              => $this->expires_at?->toIso8601String(),
            'is_active'               => $this->is_active,
            'status'                  => $statusLabel,
            'created_at'              => $this->created_at?->toIso8601String(),
            'updated_at'              => $this->updated_at?->toIso8601String(),
        ];
    }
}
