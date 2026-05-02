<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "ProductVariant",
    title: "ProductVariant",
    description: "Product variant (color/size combination)",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "name", type: "string", example: "Red / XL"),
        new OA\Property(property: "sku", type: "string", nullable: true, example: "SHIRT-RED-XL"),
        new OA\Property(property: "price", type: "number", format: "float", nullable: true, example: 35.00),
        new OA\Property(property: "stock_qty", type: "integer", example: 50),
        new OA\Property(
            property: "attributes",
            type: "object",
            nullable: true,
            example: ["Color" => "Red", "Size" => "XL"]
        ),
    ]
)]
class ProductVariant {}
