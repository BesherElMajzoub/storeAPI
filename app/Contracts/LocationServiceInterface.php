<?php

namespace App\Contracts;

interface LocationServiceInterface
{
    /**
     * Get address suggestions.
     *
     * @param string $query
     * @param string $sessionToken
     * @return array
     */
    public function autocomplete(string $query, string $sessionToken): array;

    /**
     * Get Place details and map them to a normalized address structure.
     *
     * @param string $placeId
     * @param string $sessionToken
     * @return array
     */
    public function details(string $placeId, string $sessionToken): array;
}
