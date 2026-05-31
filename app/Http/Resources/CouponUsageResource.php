<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponUsageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'coupon_id'       => $this->coupon_id,
            'order_id'        => $this->order_id,
            'order_number'    => $this->order?->order_number,
            'user'            => $this->user ? [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ] : null,
            'discount_amount' => $this->discount_amount,
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
