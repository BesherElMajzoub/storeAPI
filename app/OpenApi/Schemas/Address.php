<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "Address",
    title: "Address",
    description: "User Address schema",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "title", type: "string", example: "Home"),
        new OA\Property(property: "address_line_1", type: "string", example: "123 Main St"),
        new OA\Property(property: "address_line_2", type: "string", nullable: true, example: "Apt 4B"),
        new OA\Property(property: "city", type: "string", example: "New York"),
        new OA\Property(property: "state", type: "string", example: "NY"),
        new OA\Property(property: "postal_code", type: "string", example: "10001"),
        new OA\Property(property: "country", type: "string", example: "USA"),
        new OA\Property(property: "phone", type: "string", example: "123-456-7890"),
        new OA\Property(property: "is_default", type: "boolean", example: true),
        new OA\Property(property: "created_at", type: "string", format: "date-time")
    ]
)]
class Address {}
