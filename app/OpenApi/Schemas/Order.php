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
        new OA\Property(property: "status", type: "string", example: "pending"),
        new OA\Property(property: "payment_status", type: "string", example: "paid"),
        new OA\Property(property: "subtotal", type: "number", format: "float", example: 299.99),
        new OA\Property(property: "tax", type: "number", format: "float", example: 0.00),
        new OA\Property(property: "shipping_cost", type: "number", format: "float", example: 15.00),
        new OA\Property(property: "discount", type: "number", format: "float", example: 0.00),
        new OA\Property(property: "total", type: "number", format: "float", example: 314.99),
        new OA\Property(property: "shipping_address", type: "object", ref: "#/components/schemas/Address"),
        new OA\Property(property: "billing_address", type: "object", ref: "#/components/schemas/Address", nullable: true),
        new OA\Property(property: "items", type: "array", items: new OA\Items(ref: "#/components/schemas/OrderItem")),
        new OA\Property(property: "stripe_session_id", type: "string", nullable: true, example: "cs_test_a1b2c3d4"),
        new OA\Property(property: "stripe_payment_intent_id", type: "string", nullable: true, example: "pi_123456789"),
        new OA\Property(property: "paid_at", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "cancelled_at", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "refunded_at", type: "string", format: "date-time", nullable: true),
        new OA\Property(property: "created_at", type: "string", format: "date-time")
    ]
)]
class Order {}
