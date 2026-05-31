<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "Coupon",
    title: "Coupon",
    description: "Coupon schema detailing coupon constraints and parameters",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "code", type: "string", example: "SAVE50"),
        new OA\Property(property: "type", type: "string", enum: ["percentage", "fixed"], example: "percentage"),
        new OA\Property(property: "value", type: "number", format: "float", example: 20.00),
        new OA\Property(property: "minimum_order_amount", type: "number", format: "float", nullable: true, example: 50.00),
        new OA\Property(property: "maximum_discount_amount", type: "number", format: "float", nullable: true, example: 100.00),
        new OA\Property(property: "usage_limit", type: "integer", nullable: true, example: 100),
        new OA\Property(property: "used_count", type: "integer", example: 0),
        new OA\Property(property: "remaining_uses", type: "integer", nullable: true, example: 100),
        new OA\Property(property: "usage_limit_per_user", type: "integer", nullable: true, example: 1),
        new OA\Property(property: "starts_at", type: "string", format: "date-time", nullable: true, example: "2026-06-01T00:00:00Z"),
        new OA\Property(property: "expires_at", type: "string", format: "date-time", nullable: true, example: "2026-12-31T23:59:59Z"),
        new OA\Property(property: "is_active", type: "boolean", example: true),
        new OA\Property(property: "status", type: "string", enum: ["active", "inactive", "expired", "scheduled"], example: "active"),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
        new OA\Property(property: "updated_at", type: "string", format: "date-time")
    ]
)]
class Coupon {}
