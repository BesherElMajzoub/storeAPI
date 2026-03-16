<?php

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "ValidationErrorResponse",
    title: "Validation Error Response",
    description: "Response for 422 validation errors",
    properties: [
        new OA\Property(property: "message", type: "string", example: "The given data was invalid."),
        new OA\Property(
            property: "errors",
            type: "object",
            additionalProperties: new OA\AdditionalProperties(
                type: "array",
                items: new OA\Items(type: "string", example: "The email field is required.")
            )
        )
    ]
)]
#[OA\Response(
    response: "ValidationErrorResponse",
    description: "Validation error response",
    content: new OA\JsonContent(ref: "#/components/schemas/ValidationErrorResponse")
)]
class ValidationErrorResponse {}
