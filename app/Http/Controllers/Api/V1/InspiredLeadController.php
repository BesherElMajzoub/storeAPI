<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreInspiredLeadRequest;
use App\Models\InspiredLead;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class InspiredLeadController extends Controller
{
    #[OA\Post(
        path: "/api/v1/inspired-leads",
        summary: "Submit Stay Inspired Form (Phone)",
        description: "Saves a new phone lead. If phone is already registered, returns a graceful success message.",
        tags: ["Public"]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["phone"],
            properties: [
                new OA\Property(property: "phone", type: "string", example: "+1234567890"),
                new OA\Property(property: "name", type: "string", example: "John Doe", nullable: true),
                new OA\Property(property: "source", type: "string", example: "stay_inspired", default: "stay_inspired")
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: "Lead saved successfully",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Your phone number has been saved successfully."),
                new OA\Property(property: "data", nullable: true),
                new OA\Property(property: "errors", nullable: true)
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: "Lead already exists",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Your phone number is already registered. We will be in touch!")
            ]
        )
    )]
    #[OA\Response(response: 422, ref: "#/components/responses/ErrorResponse")]
    public function store(StoreInspiredLeadRequest $request): JsonResponse
    {
        $validated = $request->validated();
        
        $lead = InspiredLead::where('phone', $validated['phone'])->first();

        if ($lead) {
            // Already exists, we can optionally update the name/source or just return success
            return response()->json([
                'success' => true,
                'message' => 'Your phone number is already registered. We will be in touch!',
                'data' => null,
                'errors' => null,
            ]);
        }

        InspiredLead::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Your phone number has been saved successfully.',
            'data' => null,
            'errors' => null,
        ], 201);
    }
}
