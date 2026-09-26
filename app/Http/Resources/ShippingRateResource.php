<?php

namespace App\Http\Resources;

use App\Models\ShippingRateQuote;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ShippingRateQuote */
class ShippingRateResource extends JsonResource
{
    public function __construct($resource, private readonly ?CarbonInterface $quoteExpiresAt = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'carrier' => $this->carrier,
            'service' => $this->service,
            'rate' => (float) $this->rate,
            'currency' => $this->currency,
            'delivery_days' => isset($this->delivery_days) ? (int) $this->delivery_days : null,
            'delivery_date' => $this->delivery_date ?? null,
            'expires_at' => $this->quoteExpiresAt?->toIso8601String(),
        ];
    }
}
