<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Str;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $causer = $this->whenLoaded('causer');

        return [
            'id' => $this->id,
            'action' => $this->action,
            'description' => $this->description,
            'ip_address' => $this->ip_address,
            'changes' => $this->redact((array) ($this->changes ?? [])),
            'causer' => $causer instanceof MissingValue || ! $causer ? null : [
                'id' => $causer->getKey(),
                'type' => class_basename($causer),
                'name' => $causer->name ?? null,
                'email' => $causer->email ?? null,
            ],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (Str::contains(Str::lower((string) $key), ['password', 'token', 'secret', 'authorization', 'cookie'])) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
