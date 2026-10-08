<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Order',
    title: 'Order',
    description: 'Order schema',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'order_number', type: 'string', example: 'ORD-2023-1001'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'pending_payment', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'], example: 'pending_payment'),
        new OA\Property(property: 'payment_status', type: 'string', enum: ['unpaid', 'authorized', 'paid', 'failed', 'voided', 'refunded'], example: 'unpaid', description: '`authorized` = card held at checkout, captured after the cancel window closes or when the label is bought. `voided` = cancelled before any money was taken (unpaid order closed or card hold released). `failed` = a payment or capture really failed.'),
        new OA\Property(property: 'subtotal', type: 'number', format: 'float', example: 299.99),
        new OA\Property(property: 'tax', type: 'number', format: 'float', example: 0.00),
        new OA\Property(property: 'shipping_cost', type: 'number', format: 'float', example: 15.00),
        new OA\Property(property: 'free_shipping_reason', type: 'string', enum: ['threshold', 'coupon'], nullable: true, example: 'threshold'),
        new OA\Property(property: 'discount', type: 'number', format: 'float', example: 0.00),
        new OA\Property(property: 'refunded_amount', type: 'number', format: 'float', example: 0.00),
        new OA\Property(
            property: 'refund',
            description: 'Money return for a cancelled order, independent of `status`.',
            type: 'object',
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['none', 'pending', 'released', 'succeeded', 'failed'], example: 'none', description: '`released` = card hold cancelled (never charged), `succeeded` = charge refunded, `failed` = needs admin follow-up.'),
                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 0.00, description: 'Amount returned or being returned.'),
                new OA\Property(property: 'currency', type: 'string', example: 'usd'),
            ]
        ),
        new OA\Property(
            property: 'cancellation',
            description: 'What the customer can do about cancelling right now. The frontend must not compute the window itself.',
            type: 'object',
            properties: [
                new OA\Property(property: 'mode', type: 'string', enum: ['direct', 'request', 'none'], example: 'direct', description: '`direct` = POST /orders/{id}/cancel, `request` = POST /orders/{id}/cancellation-request, `none` = not cancellable.'),
                new OA\Property(property: 'reason', type: 'string', enum: ['within_window', 'window_expired', 'label_purchased', 'fulfillment_in_progress', 'not_directly_cancellable', 'request_pending', 'payment_failed', 'shipped', 'delivered', 'cancelled', 'refunded'], example: 'within_window', description: '`payment_failed` = the held payment could not be captured; the shop will contact the customer.'),
                new OA\Property(property: 'direct_until', type: 'string', format: 'date-time', nullable: true, description: 'Server time at which direct cancellation closes (for a countdown).'),
                new OA\Property(property: 'pending_request', type: 'object', nullable: true, description: 'The pending cancellation request, when loaded.'),
            ]
        ),
        new OA\Property(property: 'authorized_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'total', type: 'number', format: 'float', example: 314.99),
        new OA\Property(property: 'shipping_address', type: 'object', ref: '#/components/schemas/Address'),
        new OA\Property(property: 'billing_address', type: 'object', ref: '#/components/schemas/Address', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderItem')),
        new OA\Property(property: 'tracking_number', type: 'string', nullable: true, deprecated: true),
        new OA\Property(property: 'shipment', ref: '#/components/schemas/Shipment', nullable: true),
        new OA\Property(property: 'stripe_session_id', type: 'string', nullable: true, example: 'cs_test_a1b2c3d4'),
        new OA\Property(property: 'fulfillment_hold', type: 'boolean', description: 'Admin only. True when the payment could not be captured: do not ship.'),
        new OA\Property(property: 'capture_failed_at', type: 'string', format: 'date-time', nullable: true, description: 'Admin only.'),
        new OA\Property(property: 'stripe_payment_intent_id', type: 'string', nullable: true, example: 'pi_123456789'),
        new OA\Property(
            property: 'payment',
            description: 'Admin-only when the payment relation is loaded.',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['pending', 'completed', 'failed', 'requires_refund', 'partially_refunded', 'refunded'], example: 'completed'),
            ]
        ),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'refunded_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'server_time', type: 'string', format: 'date-time', description: 'Server clock (UTC) when the response was built; offset the cancel countdown by `server_time - Date.now()`.'),
    ]
)]
class Order {}
