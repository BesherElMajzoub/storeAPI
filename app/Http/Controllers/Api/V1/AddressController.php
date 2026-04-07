<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAddressRequest;
use App\Http\Requests\Api\V1\UpdateAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AddressController extends Controller
{
    /**
     * GET /profile/addresses
     * List all addresses of the authenticated user.
     */
    #[OA\Get(
        path: "/api/v1/profile/addresses",
        summary: "List user addresses",
        description: "List all addresses of the authenticated user, ordered by default first.",
        security: [["bearerAuth" => []]],
        tags: ["Profile"]
    )]
    #[OA\Response(
        response: 200,
        description: "Addresses fetched successfully",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Addresses fetched."),
                new OA\Property(
                    property: "data",
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/Address")
                )
            ]
        )
    )]
    #[OA\Response(response: 401, ref: "#/components/responses/ErrorResponse")]
    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()
            ->addresses()
            ->orderBy('is_default', 'desc') // default first
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success(AddressResource::collection($addresses), 'Addresses fetched.');
    }

    /**
     * POST /profile/addresses
     * Create a new address.
     * First address is automatically set as default.
     */
    #[OA\Post(
        path: "/api/v1/profile/addresses",
        summary: "Create Address",
        description: "Create a new address for the authenticated user",
        security: [["bearerAuth" => []]],
        tags: ["Profile"]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["title", "address_line_1", "city", "state", "postal_code", "country", "phone"],
        properties: [
    new OA\Property(property: "label", type: "string", example: "work"),
    new OA\Property(property: "full_name", type: "string", example: "John Doe"),
    new OA\Property(property: "phone", type: "string", example: "+1234567890"),
    new OA\Property(property: "country", type: "string", example: "United Arab Emirates"),
    new OA\Property(property: "city", type: "string", example: "Dubai"),
    new OA\Property(property: "area", type: "string", example: "Downtown"),
    new OA\Property(property: "street", type: "string", example: "Sheikh Zayed Road"),
    new OA\Property(property: "building", type: "string", example: "Burj Khalifa"),
    new OA\Property(property: "floor", type: "string", example: "42"),
    new OA\Property(property: "apartment", type: "string", example: "4205"),
    new OA\Property(property: "postal_code", type: "string", example: "00000"),
    new OA\Property(property: "notes", type: "string", example: "Please leave packages at the reception"),
    new OA\Property(property: "is_default", type: "boolean", example: true),
]
        )
    )]
    #[OA\Response(
        response: 201,
        description: "Address created successfully",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Address created."),
                new OA\Property(property: "data", ref: "#/components/schemas/Address")
            ]
        )
    )]
    #[OA\Response(response: 422, ref: "#/components/responses/ValidationErrorResponse")]
    public function store(StoreAddressRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        // Backward compatibility for legacy address fields
        $data['name'] = $data['full_name'] ?? 'Unknown';
        $data['line1'] = $data['street'] ?? 'Unknown';

        // First address → auto default
        $hasAddresses = $user->addresses()->exists();
        if (!$hasAddresses) {
            $data['is_default'] = true;
        }

        $address = $user->addresses()->create($data);

        // If the user explicitly set is_default = true, clear others
        if (!empty($data['is_default'])) {
            $address->setAsDefault();
        }

        return $this->success(new AddressResource($address), 'Address created.', 201);
    }

    /**
     * PUT /profile/addresses/{id}
     * Update an existing address.
     */
    #[OA\Put(
        path: "/api/v1/profile/addresses/{id}",
        summary: "Update Address",
        description: "Update an existing user address",
        security: [["bearerAuth" => []]],
        tags: ["Profile"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "title", type: "string", example: "Work"),
                new OA\Property(property: "address_line_1", type: "string", example: "123 Main St"),
                new OA\Property(property: "address_line_2", type: "string", example: "Suite 100"),
                new OA\Property(property: "city", type: "string", example: "New York"),
                new OA\Property(property: "state", type: "string", example: "NY"),
                new OA\Property(property: "postal_code", type: "string", example: "10001"),
                new OA\Property(property: "country", type: "string", example: "USA"),
                new OA\Property(property: "phone", type: "string", example: "123-456-7890"),
                new OA\Property(property: "is_default", type: "boolean", example: false)
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: "Address updated successfully",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Address updated."),
                new OA\Property(property: "data", ref: "#/components/schemas/Address")
            ]
        )
    )]
    #[OA\Response(response: 403, ref: "#/components/responses/ErrorResponse")]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function update(UpdateAddressRequest $request, int $id): JsonResponse
    {
        $address = $this->findOwnAddress($request, $id);

        $data = $request->validated();
        
        // Backward compatibility for legacy address fields
        if (isset($data['full_name'])) {
            $data['name'] = $data['full_name'];
        }
        if (isset($data['street'])) {
            $data['line1'] = $data['street'];
        }

        $address->update($data);

        // If user wants to set this as default
        if (!empty($data['is_default'])) {
            $address->setAsDefault();
        }

        return $this->success(new AddressResource($address->fresh()), 'Address updated.');
    }

    /**
     * DELETE /profile/addresses/{id}
     * Delete an address.
     */
    #[OA\Delete(
        path: "/api/v1/profile/addresses/{id}",
        summary: "Delete Address",
        description: "Delete an existing user address",
        security: [["bearerAuth" => []]],
        tags: ["Profile"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Address deleted successfully",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Address deleted."),
                new OA\Property(property: "data", type: "object", nullable: true)
            ]
        )
    )]
    #[OA\Response(response: 403, ref: "#/components/responses/ErrorResponse")]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $address = $this->findOwnAddress($request, $id);
        $wasDefault = $address->is_default;

        $address->delete();

        // If deleted address was default → promote the newest remaining address to default
        if ($wasDefault) {
            $next = $request->user()->addresses()->latest()->first();
            $next?->update(['is_default' => true]);
        }

        return $this->success(null, 'Address deleted.');
    }

    /**
     * POST /profile/addresses/{id}/default
     * Set a specific address as default.
     */
    #[OA\Post(
        path: "/api/v1/profile/addresses/{id}/default",
        summary: "Set Default Address",
        description: "Set a specific user address as the default",
        security: [["bearerAuth" => []]],
        tags: ["Profile"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Default address updated successfully",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Default address updated."),
                new OA\Property(property: "data", ref: "#/components/schemas/Address")
            ]
        )
    )]
    #[OA\Response(response: 403, ref: "#/components/responses/ErrorResponse")]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $address = $this->findOwnAddress($request, $id);
        $address->setAsDefault();

        return $this->success(new AddressResource($address->fresh()), 'Default address updated.');
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Find an address that belongs to the authenticated user.
     * Aborts with 404 if not found, 403 if owned by another user.
     */
    private function findOwnAddress(Request $request, int $id): Address
    {
        $address = Address::findOrFail($id);

        if ($address->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this address.');
        }

        return $address;
    }

    private function success(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $status);
    }
}
