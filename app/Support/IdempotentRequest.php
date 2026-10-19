<?php

namespace App\Support;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Answers a repeated click with the first result (rule R3). The browser sends
 * an `Idempotency-Key` header with each click; it only guards against double
 * clicks and never reaches Stripe, which gets its own key from the backend.
 * Server errors are not remembered, so a failed click can be repeated.
 */
final class IdempotentRequest
{
    private const TTL_SECONDS = 600;

    /** @param  Closure(): JsonResponse  $callback */
    public static function run(Request $request, string $scope, Closure $callback): JsonResponse
    {
        $clientKey = trim((string) $request->header('Idempotency-Key'));
        if ($clientKey === '' || strlen($clientKey) > 128) {
            return $callback();
        }

        $cacheKey = 'idempotent:'.sha1($scope.'|'.($request->user()?->getAuthIdentifier() ?? '').'|'.$clientKey);

        return Cache::lock($cacheKey.':lock', 30)->block(10, function () use ($cacheKey, $callback): JsonResponse {
            if (is_array($stored = Cache::get($cacheKey))) {
                return response()->json($stored['body'], $stored['status'], ['Idempotent-Replay' => 'true']);
            }

            $response = $callback();

            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, ['status' => $response->getStatusCode(), 'body' => $response->getData(true)], self::TTL_SECONDS);
            }

            return $response;
        });
    }
}
