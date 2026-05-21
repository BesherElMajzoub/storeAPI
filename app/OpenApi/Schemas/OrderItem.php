<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "OrderItem",
    title: "Order Item",
    description: "Order Item schema",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "order_id", type: "integer", example: 1),
        new OA\Property(property: "product_id", type: "integer", example: 10),
        new OA\Property(property: "product_name", type: "string", example: "MacBook Pro"),
        new OA\Property(property: "variant_name", type: "string", nullable: true, example: "Silver"),
        new OA\Property(property: "sku", type: "string", nullable: true, example: "MBP-SLV"),
        new OA\Property(property: "quantity", type: "integer", example: 2),
        new OA\Property(property: "price", type: "number", format: "float", example: 149.99),
        new OA\Property(property: "total", type: "number", format: "float", example: 299.98),
        new OA\Property(
            property: "variant_attributes",
            type: "object",
            nullable: true,
            description: "Selected variant attributes snapshot",
            example: ["color" => "Gold", "size" => "18in"]
        ),
        new OA\Property(
            property: "attributes",
            type: "object",
            nullable: true,
            description: "Selected variant attributes alias",
            example: ["color" => "Gold", "size" => "18in"]
        ),
        new OA\Property(
            property: "variant",
            type: "object",
            nullable: true,
            properties: [
                new OA\Property(property: "id", type: "integer", example: 2),
                new OA\Property(property: "name", type: "string", example: "Gold / 18in"),
                new OA\Property(property: "sku", type: "string", example: "NK-G-18"),
                new OA\Property(
                    property: "attributes",
                    type: "object",
                    description: "Attributes map for variant",
                    example: ["color" => "Gold", "size" => "18in"]
                ),
                new OA\Property(
                    property: "options",
                    type: "object",
                    description: "Options map alias for variant",
                    example: ["color" => "Gold", "size" => "18in"]
                )
            ]
        )
    ]
)]
class OrderItem {}
