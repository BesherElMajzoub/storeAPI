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
        new OA\Property(property: "description", type: "string", nullable: true, example: "High performance laptop"),
        new OA\Property(property: "price", type: "number", format: "float", example: 1999.99),
        new OA\Property(property: "discount_price", type: "number", format: "float", nullable: true, example: 1799.99),
        new OA\Property(property: "final_price", type: "number", format: "float", example: 1799.99),
        new OA\Property(property: "stock_qty", type: "integer", example: 10),
        new OA\Property(property: "sku", type: "string", nullable: true, example: "APL-MBC-2023"),
        new OA\Property(property: "in_stock", type: "boolean", example: true),
        new OA\Property(property: "is_featured", type: "boolean", example: false),
        new OA\Property(property: "rating", type: "number", format: "float", example: 4.8),
        new OA\Property(property: "reviews_count", type: "integer", example: 15),
        new OA\Property(property: "category", ref: "#/components/schemas/CategoryBasic", nullable: true),
        new OA\Property(
            property: "image",
            type: "object",
            nullable: true,
            properties: [
                new OA\Property(property: "thumb", type: "string", format: "uri", nullable: true),
                new OA\Property(property: "card", type: "string", format: "uri", nullable: true),
                new OA\Property(property: "detail", type: "string", format: "uri", nullable: true, description: "Only in product detail endpoint"),
                new OA\Property(property: "zoom", type: "string", format: "uri", nullable: true, description: "Only in product detail endpoint"),
            ]
        ),
        new OA\Property(
            property: "gallery",
            type: "array",
            nullable: true,
            description: "Only in product detail endpoint",
            items: new OA\Items(
                type: "object",
                properties: [
                    new OA\Property(property: "id", type: "integer"),
                    new OA\Property(property: "thumb", type: "string", format: "uri", nullable: true),
                    new OA\Property(property: "card", type: "string", format: "uri", nullable: true),
                    new OA\Property(property: "detail", type: "string", format: "uri", nullable: true),
                    new OA\Property(property: "zoom", type: "string", format: "uri", nullable: true),
                    new OA\Property(property: "order", type: "integer"),
                ]
            )
        ),
        new OA\Property(
            property: "variants",
            type: "array",
            nullable: true,
            description: "Only in product detail endpoint",
            items: new OA\Items(ref: "#/components/schemas/ProductVariant")
        ),
        new OA\Property(
            property: "attributes",
            type: "object",
            nullable: true,
            description: "Only in product detail endpoint"
        ),
    ]
)]
class Product {}
