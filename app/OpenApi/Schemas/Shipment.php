<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Shipment',
    title: 'Shipment',
    properties: [
        new OA\Property(property: 'tracking_number', type: 'string'),
        new OA\Property(property: 'carrier', type: 'string', nullable: true),
        new OA\Property(property: 'service', type: 'string', nullable: true),
        new OA\Property(property: 'tracking_url', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'label_url', type: 'string', format: 'uri', nullable: true, description: 'Admin responses only'),
        new OA\Property(property: 'shipped_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'estimated_delivery', type: 'string', format: 'date', nullable: true),
        new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['unknown', 'pre_transit', 'in_transit', 'out_for_delivery', 'available_for_pickup', 'delivered', 'return_to_sender', 'failure', 'cancelled', 'error']
        ),
    ]
)]
class Shipment {}
