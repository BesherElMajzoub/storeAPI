<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "Product",
    title: "Product",
    description: "Product schema",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "name", type: "string", example: "MacBook Pro"),
        new OA\Property(property: "slug", type: "string", example: "macbook-pro"),
        new OA\Property(property: "description", type: "string", example: "High performance laptop"),
        new OA\Property(property: "price", type: "number", format: "float", example: 1999.99),
        new OA\Property(property: "stock", type: "integer", example: 10),
        new OA\Property(property: "sku", type: "string", example: "APL-MBC-2023"),
        new OA\Property(property: "category_id", type: "integer", example: 1),
        new OA\Property(property: "is_active", type: "boolean", example: true),
        new OA\Property(property: "image", type: "string", nullable: true, example: "products/macbook.png"),
        new OA\Property(property: "average_rating", type: "number", format: "float", example: 4.8),
        new OA\Property(property: "reviews_count", type: "integer", example: 15),
        new OA\Property(property: "created_at", type: "string", format: "date-time", nullable: true)
    ]
)]
class Product {}
