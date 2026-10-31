<?php

namespace App\Contracts;

interface LocationServiceInterface
{
    /**
     * Get address suggestions, favouring results near $bias when given.
     *
     * @param  array{lat: float, lng: float}|null  $bias
     */
    public function autocomplete(string $query, string $sessionToken, ?array $bias = null): array;

    /**
     * Get Place details and map them to a normalized address structure.
     */
    public function details(string $placeId, string $sessionToken): array;
}
