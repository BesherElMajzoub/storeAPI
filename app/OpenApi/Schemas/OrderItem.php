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
        new OA\Property(property: "quantity", type: "integer", example: 2),
        new OA\Property(property: "unit_price", type: "number", format: "float", example: 149.99),
        new OA\Property(property: "subtotal", type: "number", format: "float", example: 299.98)
    ]
)]
class OrderItem {}
