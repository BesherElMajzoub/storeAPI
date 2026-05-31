<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddressAutocompleteRequest;
use App\Http\Requests\Api\V1\AddressDetailsRequest;
use App\Http\Requests\Api\V1\StoreAddressRequest;
use App\Http\Requests\Api\V1\UpdateAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Contracts\LocationServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AddressController extends Controller
{
    private LocationServiceInterface $locationService;

    public function __construct(LocationServiceInterface $locationService)
    {
        $this->locationService = $locationService;
    }

    #[OA\Get(
        path: "/api/v1/profile/addresses",
        summary: "List Saved Addresses",
        description: "Get all saved addresses of the authenticated user.",
        security: [["bearerAuth" => []]],
        tags: ["Addresses"],
        responses: [
            new OA\Response(
                response: 200,
                description: "Addresses retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Addresses retrieved successfully."),
                        new OA\Property(
                            property: "data",
                            type: "array",
                            items: new OA\Items(ref: "#/components/schemas/Address")
                        ),
                        new OA\Property(property: "errors", type: "object", nullable: true, example: null)
                    ]
                )
            ),
            new OA\Response(response: 401, ref: "#/components/responses/UnauthorizedResponse")
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()->addresses()->latest()->get();

        return response()->json([
            'success' => true,
            'message' => 'Addresses retrieved successfully.',
            'data'    => AddressResource::collection($addresses),
            'errors'  => null,
        ]);
    }

    #[OA\Post(
        path: "/api/v1/profile/addresses",
        summary: "Create Saved Address",
        description: "Save a new address to the user's address book.",
        security: [["bearerAuth" => []]],
        tags: ["Addresses"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["label", "full_name", "phone", "country", "city", "street"],
                properties: [
                    new OA\Property(property: "label", type: "string", enum: ["home", "work", "other"], example: "home"),
                    new OA\Property(property: "full_name", type: "string", example: "John Doe"),
                    new OA\Property(property: "phone", type: "string", example: "+1234567890"),
                    new OA\Property(property: "country", type: "string", example: "US"),
                    new OA\Property(property: "city", type: "string", example: "New York"),
                    new OA\Property(property: "area", type: "string", nullable: true, example: "Manhattan"),
                    new OA\Property(property: "street", type: "string", example: "5th Avenue"),
                    new OA\Property(property: "building", type: "string", nullable: true, example: "Flatiron Building"),
                    new OA\Property(property: "floor", type: "string", nullable: true, example: "12"),
                    new OA\Property(property: "apartment", type: "string", nullable: true, example: "12B"),
                    new OA\Property(property: "postal_code", type: "string", nullable: true, example: "10010"),
                    new OA\Property(property: "notes", type: "string", nullable: true, example: "Leave at door"),
                    new OA\Property(property: "is_default", type: "boolean", example: false)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "Address saved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Address saved successfully."),
                        new OA\Property(property: "data", ref: "#/components/schemas/Address"),
                        new OA\Property(property: "errors", type: "object", nullable: true, example: null)
                    ]
                )
            ),
            new OA\Response(response: 422, ref: "#/components/responses/ValidationErrorResponse"),
            new OA\Response(response: 401, ref: "#/components/responses/UnauthorizedResponse")
        ]
    )]
    public function store(StoreAddressRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        // Populate legacy fields for database compatibility
        $data['name'] = $data['full_name'];
        $data['type'] = 'shipping';
        $data['line1'] = $data['street'];

        // If this is the user's first address, make it default automatically
        if ($user->addresses()->count() === 0) {
            $data['is_default'] = true;
        }

        $address = $user->addresses()->create($data);

        // If set as default, clear other default addresses
        if ($address->is_default) {
            $address->setAsDefault();
        }

        return response()->json([
            'success' => true,
            'message' => 'Address saved successfully.',
            'data'    => new AddressResource($address),
            'errors'  => null,
        ], 201);
    }

    #[OA\Put(
        path: "/api/v1/profile/addresses/{id}",
        summary: "Update Saved Address",
        description: "Update the details of a saved address.",
        security: [["bearerAuth" => []]],
        tags: ["Addresses"],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "label", type: "string", enum: ["home", "work", "other"]),
                    new OA\Property(property: "full_name", type: "string"),
                    new OA\Property(property: "phone", type: "string"),
                    new OA\Property(property: "country", type: "string"),
                    new OA\Property(property: "city", type: "string"),
                    new OA\Property(property: "area", type: "string", nullable: true),
                    new OA\Property(property: "street", type: "string"),
                    new OA\Property(property: "building", type: "string", nullable: true),
                    new OA\Property(property: "floor", type: "string", nullable: true),
                    new OA\Property(property: "apartment", type: "string", nullable: true),
                    new OA\Property(property: "postal_code", type: "string", nullable: true),
                    new OA\Property(property: "notes", type: "string", nullable: true),
                    new OA\Property(property: "is_default", type: "boolean")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Address updated successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Address updated successfully."),
                        new OA\Property(property: "data", ref: "#/components/schemas/Address"),
                        new OA\Property(property: "errors", type: "object", nullable: true, example: null)
                    ]
                )
            ),
            new OA\Response(response: 403, ref: "#/components/responses/ForbiddenResponse"),
            new OA\Response(response: 404, ref: "#/components/responses/NotFoundResponse"),
            new OA\Response(response: 422, ref: "#/components/responses/ValidationErrorResponse")
        ]
    )]
    public function update(UpdateAddressRequest $request, int $id): JsonResponse
    {
        $address = Address::findOrFail($id);

        if ($address->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'This action is unauthorized.',
                'data'    => null,
                'errors'  => null,
            ], 403);
        }

        $data = $request->validated();
        if (isset($data['full_name'])) {
            $data['name'] = $data['full_name'];
        }
        if (isset($data['street'])) {
            $data['line1'] = $data['street'];
        }

        $address->update($data);

        if ($address->is_default) {
            $address->setAsDefault();
        }

        return response()->json([
            'success' => true,
            'message' => 'Address updated successfully.',
            'data'    => new AddressResource($address),
            'errors'  => null,
        ]);
    }

    #[OA\Delete(
        path: "/api/v1/profile/addresses/{id}",
        summary: "Delete Saved Address",
        description: "Remove an address from the user's address book.",
        security: [["bearerAuth" => []]],
        tags: ["Addresses"],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Address deleted successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Address deleted successfully."),
                        new OA\Property(property: "data", type: "object", nullable: true, example: null),
                        new OA\Property(property: "errors", type: "object", nullable: true, example: null)
                    ]
                )
            ),
            new OA\Response(response: 403, ref: "#/components/responses/ForbiddenResponse"),
            new OA\Response(response: 404, ref: "#/components/responses/NotFoundResponse")
        ]
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $address = Address::findOrFail($id);

        if ($address->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'This action is unauthorized.',
                'data'    => null,
                'errors'  => null,
            ], 403);
        }

        $wasDefault = $address->is_default;
        $address->delete();

        // If we deleted the default address, make another address default if available
        if ($wasDefault) {
            $nextAddress = $request->user()->addresses()->first();
            if ($nextAddress) {
                $nextAddress->setAsDefault();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Address deleted successfully.',
            'data'    => null,
            'errors'  => null,
        ]);
    }

    #[OA\Post(
        path: "/api/v1/profile/addresses/{id}/default",
        summary: "Set Default Address",
        description: "Set an address as the user's primary/default address.",
        security: [["bearerAuth" => []]],
        tags: ["Addresses"],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Default address updated successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Default address updated successfully."),
                        new OA\Property(property: "data", ref: "#/components/schemas/Address"),
                        new OA\Property(property: "errors", type: "object", nullable: true, example: null)
                    ]
                )
            ),
            new OA\Response(response: 403, ref: "#/components/responses/ForbiddenResponse"),
            new OA\Response(response: 404, ref: "#/components/responses/NotFoundResponse")
        ]
    )]
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $address = Address::findOrFail($id);

        if ($address->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'This action is unauthorized.',
                'data'    => null,
                'errors'  => null,
            ], 403);
        }

        $address->setAsDefault();

        return response()->json([
            'success' => true,
            'message' => 'Default address updated successfully.',
            'data'    => new AddressResource($address),
            'errors'  => null,
        ]);
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
        $result = $this->locationService->autocomplete(
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
        $result = $this->locationService->details(
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
