<?php

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "PaginationMeta",
    title: "Pagination Meta",
    description: "Metadata for paginated responses (Laravel style)",
    properties: [
        new OA\Property(property: "current_page", type: "integer", example: 1),
        new OA\Property(property: "from", type: "integer", example: 1),
        new OA\Property(property: "last_page", type: "integer", example: 5),
        new OA\Property(property: "links", type: "array", items: new OA\Items(
            properties: [
                new OA\Property(property: "url", type: "string", nullable: true, example: "http://example.com/api?page=1"),
                new OA\Property(property: "label", type: "string", example: "1"),
                new OA\Property(property: "active", type: "boolean", example: true)
            ],
            type: "object"
        )),
        new OA\Property(property: "path", type: "string", example: "http://example.com/api"),
        new OA\Property(property: "per_page", type: "integer", example: 15),
        new OA\Property(property: "to", type: "integer", example: 15),
        new OA\Property(property: "total", type: "integer", example: 75)
    ],
    type: "object"
)]
class PaginationMeta {}
