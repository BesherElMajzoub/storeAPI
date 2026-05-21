<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\GeoLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class GeoController extends Controller
{
    #[OA\Get(
        path: "/api/v1/admin/geo/me",
        summary: "Admin – IP Geo-location",
        description: "Returns geo-location data for the caller's IP address. Reads Cloudflare's `CF-IPCountry` header first (zero cost). Falls back to **ipapi.co** when the header is absent.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Geo"]
    )]
    #[OA\Response(
        response: 200,
        description: "Geo-location resolved",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string",  example: "Geo-location resolved."),
                new OA\Property(
                    property: "data",
                    type: "object",
                    properties: [
                        new OA\Property(property: "ip",           type: "string",      example: "185.60.112.10"),
                        new OA\Property(property: "country_code", type: "string",      nullable: true, example: "SA"),
                        new OA\Property(property: "country_name", type: "string",      nullable: true, example: "Saudi Arabia"),
                        new OA\Property(property: "region",       type: "string",      nullable: true, example: "Riyadh"),
                        new OA\Property(property: "city",         type: "string",      nullable: true, example: "Riyadh"),
                        new OA\Property(property: "source",       type: "string",      nullable: true, example: "cloudflare",
                            description: "Resolution source: `cloudflare` | `ipapi.co` | null"),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: "#/components/responses/ErrorResponse")]
    #[OA\Response(response: 403, ref: "#/components/responses/ErrorResponse")]
    public function me(Request $request, GeoLocationService $geo): JsonResponse
    {
        $result = $geo->resolve($request);

        return response()->json([
            'success' => true,
            'message' => 'Geo-location resolved.',
            'data'    => array_merge(['ip' => $request->ip()], $result),
            'errors'  => null,
        ]);
    }
}
