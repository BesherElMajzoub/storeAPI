<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "Category",
    title: "Category",
    description: "Product category",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "name", type: "string", example: "Electronics"),
        new OA\Property(property: "slug", type: "string", example: "electronics"),
        new OA\Property(property: "description", type: "string", nullable: true, example: "Electronic devices and accessories"),
        new OA\Property(property: "parent_id", type: "integer", nullable: true, example: null),
        new OA\Property(property: "is_active", type: "boolean", example: true),
        new OA\Property(property: "image", type: "string", nullable: true, example: "categories/xyz.png"),
        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2023-01-01T00:00:00Z")
    ]
)]
class Category {}
