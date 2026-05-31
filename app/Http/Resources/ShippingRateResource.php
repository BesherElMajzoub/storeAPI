<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'carrier'       => $this->carrier,
            'service'       => $this->service,
            'rate'          => (float) $this->rate,
            'currency'      => $this->currency,
            'delivery_days' => isset($this->delivery_days) ? (int) $this->delivery_days : null,
            'delivery_date' => $this->delivery_date ?? null,
        ];
    }
}
