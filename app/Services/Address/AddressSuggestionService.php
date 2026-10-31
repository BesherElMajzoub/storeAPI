<?php

namespace App\Services\Address;

use App\Contracts\LocationServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Address suggestions near the customer: works out where to look (bias), caches identical
 * requests, and turns any provider failure into an empty list so the field stays a plain text box.
 */
class AddressSuggestionService
{
    private const CACHE_HOURS = 24;

    public function __construct(
        private readonly LocationServiceInterface $location,
        private readonly AddressBiasResolver $biasResolver,
    ) {}

    /** @return list<array{place_id: string, description: string, main_text: string, secondary_text: string}> */
    public function suggest(string $query, string $sessionToken, ?string $postalCode, ?string $state, ?string $ip): array
    {
        $bias = $this->biasResolver->resolve($postalCode, $state, $ip);

        // About 10 km of precision, so neighbours share a cache entry. The IP itself is never part of the key.
        $key = 'address:autocomplete:'.sha1(mb_strtolower(trim($query)).'|'.($bias['point']
            ? round($bias['point']['lat'], 1).','.round($bias['point']['lng'], 1)
            : 'none'));

        $cached = Cache::get($key);
        if (is_array($cached)) {
            $this->log($bias['source'], count($cached), true);

            return $cached;
        }

        $result = $this->location->autocomplete($query, $sessionToken, $bias['point']);

        if (($result['status'] ?? 500) !== 200) {
            $this->log($bias['source'], 0, false, $result['status'] ?? 500);

            return [];
        }

        $suggestions = $result['data'] ?? [];
        Cache::put($key, $suggestions, now()->addHours(self::CACHE_HOURS));
        $this->log($bias['source'], count($suggestions), false);

        return $suggestions;
    }

    // Which bias was used and how many results, never the address text or the IP.
    private function log(string $bias, int $results, bool $cached, ?int $providerStatus = null): void
    {
        Log::channel('address')->info('address.autocomplete', array_filter([
            'bias' => $bias,
            'results' => $results,
            'cached' => $cached,
            'provider_status' => $providerStatus,
        ], fn ($value) => $value !== null));
    }
}
