<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddressAutocompleteRequest;
use App\Http\Requests\Api\V1\AddressDetailsRequest;
use App\Services\GooglePlacesService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class AddressController extends Controller
{
    private GooglePlacesService $googlePlaces;

    public function __construct(GooglePlacesService $googlePlaces)
    {
        $this->googlePlaces = $googlePlaces;
    }

    #[OA\Get(
        path: "/api/v1/address/autocomplete",
        summary: "Address Autocomplete",
        description: "Get address autocomplete suggestions using Google Places API proxy.",
        tags: ["Addresses"],
        parameters: [
            new OA\Parameter(name: "q", in: "query", required: true, description: "Search query", schema: new OA\Schema(type: "string", minLength: 2, maxLength: 255)),
            new OA\Parameter(name: "session", in: "query", required: true, description: "Session UUID to link related autocomplete and details requests", schema: new OA\Schema(type: "string", format: "uuid"))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Address suggestions fetched successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Address suggestions fetched successfully"),
                        new OA\Property(
                            property: "data",
                            type: "array",
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: "place_id", type: "string"),
                                    new OA\Property(property: "description", type: "string"),
                                    new OA\Property(property: "main_text", type: "string"),
                                    new OA\Property(property: "secondary_text", type: "string"),
                                ]
                            )
                        )
                    ]
                )
            ),
            new OA\Response(response: 422, ref: "#/components/responses/ValidationErrorResponse"),
            new OA\Response(response: 429, description: "Too Many Requests"),
            new OA\Response(response: 502, description: "Bad Gateway - Google API Error")
        ]
    )]
    public function autocomplete(AddressAutocompleteRequest $request): JsonResponse
    {
        $result = $this->googlePlaces->autocomplete(
            $request->query('q'),
            $request->query('session')
        );

        if ($result['status'] !== 200) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
                'data'    => null,
                'errors'  => null,
            ], $result['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Address suggestions fetched successfully',
            'data'    => $result['data'],
            'errors'  => null,
        ]);
    }

    #[OA\Get(
        path: "/api/v1/address/details",
        summary: "Address Details",
        description: "Get normalized address details for a specific place_id using Google Places API proxy.",
        tags: ["Addresses"],
        parameters: [
            new OA\Parameter(name: "place_id", in: "query", required: true, description: "Google Place ID", schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "session", in: "query", required: true, description: "Session UUID to link related autocomplete and details requests", schema: new OA\Schema(type: "string", format: "uuid"))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Address details fetched successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Address details fetched successfully"),
                        new OA\Property(
                            property: "data",
                            type: "object",
                            properties: [
                                new OA\Property(property: "line1", type: "string"),
                                new OA\Property(property: "city", type: "string"),
                                new OA\Property(property: "state", type: "string"),
                                new OA\Property(property: "postal_code", type: "string"),
                                new OA\Property(property: "country", type: "string"),
                                new OA\Property(property: "formatted_address", type: "string"),
                                new OA\Property(property: "lat", type: "number", format: "float"),
                                new OA\Property(property: "lng", type: "number", format: "float"),
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 422, ref: "#/components/responses/ValidationErrorResponse"),
            new OA\Response(response: 429, description: "Too Many Requests"),
            new OA\Response(response: 502, description: "Bad Gateway - Google API Error")
        ]
    )]
    public function details(AddressDetailsRequest $request): JsonResponse
    {
        $result = $this->googlePlaces->details(
            $request->query('place_id'),
            $request->query('session')
        );

        if ($result['status'] !== 200) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
                'data'    => null,
                'errors'  => null,
            ], $result['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Address details fetched successfully',
            'data'    => $result['data'],
            'errors'  => null,
        ]);
    }
}
