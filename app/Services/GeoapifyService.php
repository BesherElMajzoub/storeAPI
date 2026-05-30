<?php

namespace App\Services;

use App\Contracts\LocationServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoapifyService implements LocationServiceInterface
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = (string) (config('services.geoapify.api_key') ?? '');
        $this->baseUrl = (string) (config('services.geoapify.base_url') ?? 'https://api.geoapify.com');
    }

    /**
     * Get address suggestions using Geoapify Address Autocomplete API.
     */
    public function autocomplete(string $query, string $sessionToken): array
    {
        if (empty($this->apiKey)) {
            Log::error('Geoapify API key is missing.');
            return ['error' => 'Geoapify API is not configured.', 'status' => 500];
        }

        try {
            $lang = app()->getLocale(); // Default to app locale (e.g. 'ar' or 'en')
            
            $response = Http::timeout(10)
                ->retry(2, 500)
                ->get("{$this->baseUrl}/v1/geocode/autocomplete", [
                    'text'   => $query,
                    'lang'   => $lang,
                    'limit'  => 5,
                    'apiKey' => $this->apiKey,
                ]);

            if ($response->failed()) {
                Log::error('Geoapify Autocomplete failed.', ['response' => $response->json()]);
                
                if ($response->status() === 429) {
                    return ['error' => 'API quota exceeded or rate limited.', 'status' => 429];
                }
                return ['error' => 'Failed to fetch suggestions from Geoapify API.', 'status' => 502];
            }

            $data = $response->json();
            $suggestions = [];

            if (isset($data['features']) && is_array($data['features'])) {
                foreach ($data['features'] as $feature) {
                    $properties = $feature['properties'] ?? [];
                    if (isset($properties['place_id'])) {
                        $suggestions[] = [
                            'place_id'       => $properties['place_id'],
                            'description'    => $properties['formatted'] ?? '',
                            'main_text'      => $properties['address_line1'] ?? '',
                            'secondary_text' => $properties['address_line2'] ?? '',
                        ];
                    }
                }
            }

            return ['data' => $suggestions, 'status' => 200];

        } catch (\Throwable $e) {
            Log::error('Geoapify Autocomplete exception.', ['error' => $e->getMessage()]);
            return ['error' => 'A connection timeout or unexpected error occurred.', 'status' => 504];
        }
    }

    /**
     * Get Place details and map them to a normalized address structure.
     */
    public function details(string $placeId, string $sessionToken): array
    {
        if (empty($this->apiKey)) {
            Log::error('Geoapify API key is missing.');
            return ['error' => 'Geoapify API is not configured.', 'status' => 500];
        }

        try {
            $response = Http::timeout(10)
                ->retry(2, 500)
                ->get("{$this->baseUrl}/v2/place-details", [
                    'id'     => $placeId,
                    'apiKey' => $this->apiKey,
                ]);

            if ($response->failed()) {
                Log::error('Geoapify Place Details failed.', ['place_id' => $placeId, 'response' => $response->json()]);

                if ($response->status() === 400 || $response->status() === 404) {
                    return ['error' => 'Malformed or invalid place_id.', 'status' => 400];
                }
                if ($response->status() === 429) {
                    return ['error' => 'API quota exceeded or rate limited.', 'status' => 429];
                }

                return ['error' => 'Failed to fetch address details from Geoapify API.', 'status' => 502];
            }

            $data = $response->json();
            
            // Geoapify place-details returns features collection
            $feature = $data['features'][0] ?? null;
            if (!$feature) {
                return ['error' => 'Place details not found.', 'status' => 404];
            }

            return [
                'data' => $this->mapGeoapifyAddress($feature['properties'] ?? []),
                'status' => 200
            ];

        } catch (\Throwable $e) {
            Log::error('Geoapify Details exception.', ['error' => $e->getMessage()]);
            return ['error' => 'A connection timeout or unexpected error occurred.', 'status' => 504];
        }
    }

    /**
     * Helper to map Geoapify's properties to our normalized structure.
     */
    private function mapGeoapifyAddress(array $properties): array
    {
        $streetNumber = $properties['housenumber'] ?? '';
        $street = $properties['street'] ?? '';
        
        return [
            'line1'             => trim("{$streetNumber} {$street}"),
            'city'              => $properties['city'] ?? '',
            'state'             => $properties['state_code'] ?? $properties['state'] ?? '',
            'postal_code'       => $properties['postcode'] ?? '',
            'country'           => isset($properties['country_code']) ? strtoupper($properties['country_code']) : '',
            'formatted_address' => $properties['formatted'] ?? '',
            'lat'               => $properties['lat'] ?? null,
            'lng'               => $properties['lon'] ?? null,
        ];
    }
}
