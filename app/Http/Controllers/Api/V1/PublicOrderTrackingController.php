<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TrackOrderRequest;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PublicOrderTrackingController extends Controller
{
    #[OA\Post(
        path: '/api/v1/orders/track',
        summary: 'Track an order by order number and customer email',
        tags: ['Orders']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['order_number', 'email'],
            properties: [
                new OA\Property(property: 'order_number', type: 'string', example: 'OQ-10234'),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Tracking snapshot returned. Shipment-specific fields are omitted until a tracking number exists.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'message', type: 'string', example: 'Tracking information retrieved.'),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'order_number', type: 'string', example: 'ORD-ABC123'),
                new OA\Property(property: 'status', type: 'string', nullable: true, example: 'in_transit'),
                new OA\Property(property: 'estimated_delivery', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'source', type: 'string', enum: ['manual', 'easypost'], nullable: true),
                new OA\Property(property: 'carrier', type: 'string', nullable: true),
                new OA\Property(property: 'service', type: 'string', nullable: true),
                new OA\Property(property: 'tracking_number', type: 'string', nullable: true),
                new OA\Property(property: 'tracking_url', type: 'string', nullable: true),
            ]),
            new OA\Property(property: 'errors', type: 'object', nullable: true),
        ])
    )]
    #[OA\Response(response: 404, description: 'Generic not-found response for both missing orders and email mismatch')]
    #[OA\Response(response: 429, ref: '#/components/responses/TooManyRequestsResponse')]
    public function track(TrackOrderRequest $request): JsonResponse
    {
        $order = Order::query()
            ->where('order_number', $request->validated('order_number'))
            ->whereHas('user', fn ($query) => $query->where('email', mb_strtolower($request->validated('email'))))
            ->first();

        if (! $order) {
            return $this->notFound();
        }

        $data = [
            'order_number' => $order->order_number,
            // No label yet means nothing to track, not "label created".
            'status' => $order->tracking_number ? ($order->shipment_status ?? 'pre_transit') : null,
            'estimated_delivery' => $order->estimated_delivery?->format('Y-m-d'),
            'events' => $order->tracking_events ?? [],
        ];

        if ($order->tracking_number) {
            $data += [
                'source' => $order->shipment_source,
                'carrier' => $order->shipping_carrier,
                'service' => $order->shipping_service,
                'tracking_number' => $order->tracking_number,
                'tracking_url' => $order->tracking_url,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Tracking information retrieved.',
            'data' => $data,
            'errors' => null,
        ])->header('Cache-Control', 'no-store, private');
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Order tracking information was not found.',
            'data' => null,
            'errors' => null,
        ], 404)->header('Cache-Control', 'no-store, private');
    }
}
