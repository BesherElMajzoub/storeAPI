<?php

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "UnauthorizedResponse",
    title: "Unauthorized Response",
    description: "Response for 401 unauthorized error",
    properties: [
        new OA\Property(property: "success", type: "boolean", example: false),
        new OA\Property(property: "message", type: "string", example: "Unauthenticated."),
        new OA\Property(property: "data", type: "object", nullable: true, example: null),
        new OA\Property(property: "errors", type: "object", nullable: true, example: null)
    ]
)]
#[OA\Response(
    response: "UnauthorizedResponse",
    description: "Unauthorized error response",
    content: new OA\JsonContent(ref: "#/components/schemas/UnauthorizedResponse")
)]
class UnauthorizedResponse {}
