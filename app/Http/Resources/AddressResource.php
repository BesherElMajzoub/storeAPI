<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'label'       => $this->label,
            'full_name'   => $this->full_name,
            'phone'       => $this->phone,
            'country'     => $this->country,
            'city'        => $this->city,
            'area'        => $this->area,
            'street'      => $this->street,
            'building'    => $this->building,
            'floor'       => $this->floor,
            'apartment'   => $this->apartment,
            'postal_code' => $this->postal_code,
            'notes'       => $this->notes,
            'is_default'  => (bool) $this->is_default,
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}
