<?php

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "ErrorResponse",
    title: "Error Response",
    description: "Standard error response",
    properties: [
        new OA\Property(property: "success", type: "boolean", example: false),
        new OA\Property(property: "message", type: "string", example: "An error occurred."),
        new OA\Property(property: "data", type: "object", nullable: true, example: null),
        new OA\Property(property: "errors", type: "object", nullable: true, example: null)
    ]
)]
#[OA\Response(
    response: "ErrorResponse",
    description: "Standard error response",
    content: new OA\JsonContent(ref: "#/components/schemas/ErrorResponse")
)]
class ErrorResponse {}
