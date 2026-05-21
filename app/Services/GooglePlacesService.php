<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GooglePlacesService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = (string) (config('services.google.places_api_key') ?? '');
        $this->baseUrl = (string) (config('services.google.places_base_url') ?? 'https://places.googleapis.com/v1');
    }

    /**
     * Get address suggestions using Google Places Autocomplete API (New).
     */
    public function autocomplete(string $query, string $sessionToken): array
    {
        if (empty($this->apiKey)) {
            Log::error('Google Places API key is missing.');
            return ['error' => 'Google Places API is not configured.', 'status' => 500];
        }

        try {
            $response = Http::timeout(10)
                ->retry(2, 500)
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->apiKey,
                    'Content-Type'   => 'application/json',
                ])
                ->post("{$this->baseUrl}/places:autocomplete", [
                    'input'               => $query,
                    'includedRegionCodes' => ['us', 'ca'],
                    'sessionToken'        => $sessionToken,
                ]);

            if ($response->failed()) {
                Log::error('Google Places Autocomplete failed.', ['response' => $response->json()]);
                
                if ($response->status() === 429) {
                    return ['error' => 'API quota exceeded or rate limited.', 'status' => 429];
                }
                return ['error' => 'Failed to fetch suggestions from Google API.', 'status' => 502];
            }

            $data = $response->json();
            $suggestions = [];

            if (isset($data['suggestions']) && is_array($data['suggestions'])) {
                foreach ($data['suggestions'] as $suggestion) {
                    $placePrediction = $suggestion['placePrediction'] ?? [];
                    if (isset($placePrediction['placeId'])) {
                        $suggestions[] = [
                            'place_id'       => $placePrediction['placeId'],
                            'description'    => $placePrediction['text']['text'] ?? '',
                            'main_text'      => $placePrediction['structuredFormat']['mainText']['text'] ?? '',
                            'secondary_text' => $placePrediction['structuredFormat']['secondaryText']['text'] ?? '',
                        ];
                    }
                }
            }

            return ['data' => $suggestions, 'status' => 200];

        } catch (\Throwable $e) {
            Log::error('Google Places Autocomplete exception.', ['error' => $e->getMessage()]);
            return ['error' => 'A connection timeout or unexpected error occurred.', 'status' => 504];
        }
    }

    /**
     * Get Place details and map them to a normalized address structure.
     */
    public function details(string $placeId, string $sessionToken): array
    {
        if (empty($this->apiKey)) {
            Log::error('Google Places API key is missing.');
            return ['error' => 'Google Places API is not configured.', 'status' => 500];
        }

        try {
            $response = Http::timeout(10)
                ->retry(2, 500)
                ->withHeaders([
                    'X-Goog-Api-Key'   => $this->apiKey,
                    'X-Goog-FieldMask' => 'addressComponents,formattedAddress,location',
                ])
                ->get("{$this->baseUrl}/places/{$placeId}", [
                    'sessionToken' => $sessionToken,
                ]);

            if ($response->failed()) {
                Log::error('Google Places Details failed.', ['place_id' => $placeId, 'response' => $response->json()]);

                if ($response->status() === 400 || $response->status() === 404) {
                    return ['error' => 'Malformed or invalid place_id.', 'status' => 400];
                }
                if ($response->status() === 429) {
                    return ['error' => 'API quota exceeded or rate limited.', 'status' => 429];
                }

                return ['error' => 'Failed to fetch address details from Google API.', 'status' => 502];
            }

            $data = $response->json();
            
            return [
                'data' => $this->mapAddressComponents($data),
                'status' => 200
            ];

        } catch (\Throwable $e) {
            Log::error('Google Places Details exception.', ['error' => $e->getMessage()]);
            return ['error' => 'A connection timeout or unexpected error occurred.', 'status' => 504];
        }
    }

    /**
     * Helper to map Google's addressComponents to our normalized structure.
     */
    private function mapAddressComponents(array $data): array
    {
        $normalized = [
            'line1'             => '',
            'city'              => '',
            'state'             => '',
            'postal_code'       => '',
            'country'           => '',
            'formatted_address' => $data['formattedAddress'] ?? '',
            'lat'               => $data['location']['latitude'] ?? null,
            'lng'               => $data['location']['longitude'] ?? null,
        ];

        $components = $data['addressComponents'] ?? [];
        $streetNumber = '';
        $route = '';

        foreach ($components as $component) {
            $types = $component['types'] ?? [];
            $longText = $component['longText'] ?? '';
            $shortText = $component['shortText'] ?? '';

            if (in_array('street_number', $types)) {
                $streetNumber = $longText;
            } elseif (in_array('route', $types)) {
                $route = $longText;
            } elseif (in_array('locality', $types)) {
                $normalized['city'] = $longText;
            } elseif (in_array('administrative_area_level_1', $types)) {
                $normalized['state'] = $shortText; // shortText is typically better for states (e.g., CA instead of California)
            } elseif (in_array('postal_code', $types)) {
                $normalized['postal_code'] = $longText;
            } elseif (in_array('country', $types)) {
                $normalized['country'] = $shortText; // Usually use ISO alpha-2 for countries
            }
        }

        // Combine street number and route for line1
        $normalized['line1'] = trim("{$streetNumber} {$route}");

        return $normalized;
    }
}
