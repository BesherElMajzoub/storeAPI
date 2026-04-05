<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateInspiredLeadRequest;
use App\Models\InspiredLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class InspiredLeadController extends Controller
{
    #[OA\Get(
        path: "/api/v1/admin/inspired-leads",
        summary: "Admin List Inspired Leads",
        description: "List all phone leads/subscribers for admin",
        security: [["bearerAuth" => []]],
        tags: ["Admin Inspired Leads"]
    )]
    #[OA\Parameter(name: "limit", in: "query", schema: new OA\Schema(type: "integer", default: 15))]
    #[OA\Response(
        response: 200,
        description: "Leads fetched",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Leads retrieved successfully."),
                new OA\Property(property: "data", type: "object")
            ]
        )
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 15);
        $leads = InspiredLead::latest()->paginate($limit);

        return response()->json([
            'success' => true,
            'message' => 'Leads retrieved successfully.',
            'data' => $leads,
            'errors' => null,
        ]);
    }

    #[OA\Get(
        path: "/api/v1/admin/inspired-leads/{id}",
        summary: "Admin Show Inspired Lead",
        description: "Show a single inspired lead details.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Inspired Leads"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Lead fetched",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Lead retrieved."),
                new OA\Property(property: "data", type: "object")
            ]
        )
    )]
    public function show($id): JsonResponse
    {
        $lead = InspiredLead::findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Lead retrieved.',
            'data' => $lead,
            'errors' => null,
        ]);
    }

    #[OA\Put(
        path: "/api/v1/admin/inspired-leads/{id}",
        summary: "Admin Update Inspired Lead",
        description: "Update the status, notes, or info of a lead.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Inspired Leads"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "status", type: "string", enum: ["new", "contacted", "converted", "closed"], example: "contacted"),
                new OA\Property(property: "notes", type: "string", example: "Called them, left voicemail.", nullable: true),
                new OA\Property(property: "name", type: "string", example: "John Doe", nullable: true),
                new OA\Property(property: "phone", type: "string", example: "+1234567890", nullable: true)
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: "Lead updated",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Lead updated successfully."),
                new OA\Property(property: "data", type: "object")
            ]
        )
    )]
    public function update(UpdateInspiredLeadRequest $request, $id): JsonResponse
    {
        $lead = InspiredLead::findOrFail($id);
        $lead->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Lead updated successfully.',
            'data' => $lead,
            'errors' => null,
        ]);
    }

    #[OA\Delete(
        path: "/api/v1/admin/inspired-leads/{id}",
        summary: "Admin Delete Inspired Lead",
        description: "Delete an inspired lead permanently.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Inspired Leads"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Lead deleted",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Lead deleted successfully.")
            ]
        )
    )]
    public function destroy($id): JsonResponse
    {
        $lead = InspiredLead::findOrFail($id);
        $lead->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lead deleted successfully.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
