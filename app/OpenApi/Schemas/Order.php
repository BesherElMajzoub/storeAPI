<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "Order",
    title: "Order",
    description: "Order schema",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "order_number", type: "string", example: "ORD-2023-1001"),
        new OA\Property(property: "user_id", type: "integer", example: 5),
        new OA\Property(property: "status", type: "string", example: "pending"),
        new OA\Property(property: "total_amount", type: "number", format: "float", example: 299.99),
        new OA\Property(property: "payment_method", type: "string", example: "credit_card"),
        new OA\Property(property: "payment_status", type: "string", example: "paid"),
        new OA\Property(property: "shipping_address", type: "object", ref: "#/components/schemas/Address"),
        new OA\Property(property: "items", type: "array", items: new OA\Items(ref: "#/components/schemas/OrderItem")),
        new OA\Property(property: "created_at", type: "string", format: "date-time")
    ]
)]
class Order {}
