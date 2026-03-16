<?php

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "SuccessResponse",
    title: "Success Response",
    description: "Standard generic success response",
    properties: [
        new OA\Property(property: "success", type: "boolean", example: true),
        new OA\Property(property: "message", type: "string", example: "Operation completed successfully."),
        new OA\Property(property: "data", type: "object", nullable: true, example: null),
        new OA\Property(property: "errors", type: "object", nullable: true, example: null)
    ]
)]
class SuccessResponse {}
