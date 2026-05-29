<?php

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "TooManyRequestsResponse",
    title: "Too Many Requests Response",
    description: "Response for 429 too many requests error",
    properties: [
        new OA\Property(property: "success", type: "boolean", example: false),
        new OA\Property(property: "message", type: "string", example: "Too many requests. Please try again later."),
        new OA\Property(property: "data", type: "object", nullable: true, example: null),
        new OA\Property(
            property: "errors",
            type: "object",
            properties: [
                new OA\Property(
                    property: "rate_limit",
                    type: "array",
                    items: new OA\Items(type: "string", example: "Too many requests, please try again later.")
                )
            ]
        )
    ]
)]
#[OA\Response(
    response: "TooManyRequestsResponse",
    description: "Too many requests error response",
    content: new OA\JsonContent(ref: "#/components/schemas/TooManyRequestsResponse")
)]
class TooManyRequestsResponse {}
