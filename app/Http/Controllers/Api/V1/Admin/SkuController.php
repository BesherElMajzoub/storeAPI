<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\GenerateSkuRequest;
use App\Services\SkuGeneratorService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class SkuController extends Controller
{
    #[OA\Post(
        path: "/api/v1/admin/skus/generate",
        summary: "Admin Generate SKU Preview",
        description: "Generate a preview SKU for a product or variant based on the provided details. Does NOT reserve or persist the SKU.",
        security: [["bearerAuth" => []]],
        tags: ["Admin SKUs"]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["type", "category_id", "product_name"],
            properties: [
                new OA\Property(property: "type", type: "string", enum: ["product", "variant"], example: "variant"),
                new OA\Property(property: "category_id", type: "integer", example: 1),
                new OA\Property(property: "product_name", type: "string", example: "Pear Necklace"),
                new OA\Property(property: "product_slug", type: "string", nullable: true, example: "pear-necklace"),
                new OA\Property(property: "variant_name", type: "string", nullable: true, example: "Rose Gold")
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: "SKU generated successfully",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "SKU generated successfully"),
                new OA\Property(
                    property: "data",
                    type: "object",
                    properties: [
                        new OA\Property(property: "sku", type: "string", example: "OTQ-NCK-PEAR-RG-0042")
                    ]
                )
            ]
        )
    )]
    #[OA\Response(response: 422, ref: "#/components/responses/ValidationErrorResponse")]
    public function generate(GenerateSkuRequest $request, SkuGeneratorService $service): JsonResponse
    {
        $sku = $service->generate($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'SKU generated successfully',
            'data' => [
                'sku' => $sku
            ]
        ]);
    }
}
