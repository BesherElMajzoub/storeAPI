<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "Review",
    title: "Review",
    description: "Product Review schema",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "user_id", type: "integer", example: 1),
        new OA\Property(property: "product_id", type: "integer", example: 10),
        new OA\Property(property: "rating", type: "integer", example: 5),
        new OA\Property(property: "comment", type: "string", nullable: true, example: "Great product!"),
        new OA\Property(property: "user", ref: "#/components/schemas/User", nullable: true),
        new OA\Property(property: "created_at", type: "string", format: "date-time")
    ]
)]
class Review {}
