<?php

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "NotFoundResponse",
    title: "NotFound Response",
    description: "Response for 404 not found error",
    properties: [
        new OA\Property(property: "success", type: "boolean", example: false),
        new OA\Property(property: "message", type: "string", example: "Resource not found."),
        new OA\Property(property: "data", type: "object", nullable: true, example: null),
        new OA\Property(property: "errors", type: "object", nullable: true, example: null)
    ]
)]
#[OA\Response(
    response: "NotFoundResponse",
    description: "Not found error response",
    content: new OA\JsonContent(ref: "#/components/schemas/NotFoundResponse")
)]
class NotFoundResponse {}
