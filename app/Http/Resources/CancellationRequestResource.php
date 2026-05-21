<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CancellationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'status'      => $this->status,
            'reason'      => $this->reason,
            'admin_note'  => $this->admin_note,
            'created_at'  => $this->created_at?->toIso8601String(),
            'decided_at'  => $this->decided_at?->toIso8601String(),
        ];
    }
}
