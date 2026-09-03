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
    #[OA\Response(response: 200, description: 'Tracking snapshot returned')]
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

        return response()->json([
            'success' => true,
            'message' => 'Tracking information retrieved.',
            'data' => [
                'order_number' => $order->order_number,
                'status' => $order->shipment_status ?? 'pre_transit',
                'estimated_delivery' => $order->estimated_delivery?->format('Y-m-d'),
                'events' => $order->tracking_events ?? [],
            ],
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
