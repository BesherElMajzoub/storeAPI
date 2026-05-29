<?php

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "ForbiddenResponse",
    title: "Forbidden Response",
    description: "Response for 403 forbidden error",
    properties: [
        new OA\Property(property: "success", type: "boolean", example: false),
        new OA\Property(property: "message", type: "string", example: "Forbidden. You do not have permission to access this resource."),
        new OA\Property(property: "data", type: "object", nullable: true, example: null),
        new OA\Property(property: "errors", type: "object", nullable: true, example: null)
    ]
)]
#[OA\Response(
    response: "ForbiddenResponse",
    description: "Forbidden error response",
    content: new OA\JsonContent(ref: "#/components/schemas/ForbiddenResponse")
)]
class ForbiddenResponse {}
