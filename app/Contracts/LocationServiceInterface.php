<?php

namespace App\Contracts;

interface LocationServiceInterface
{
    /**
     * Get address suggestions.
     */
    public function autocomplete(string $query, string $sessionToken): array;

    /**
     * Get Place details and map them to a normalized address structure.
     */
    public function details(string $placeId, string $sessionToken): array;
}
