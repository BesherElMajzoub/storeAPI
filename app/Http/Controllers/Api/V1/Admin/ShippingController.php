<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\EasyPostService;
use App\Services\ShipmentTrackingService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class ShippingController extends Controller
{
    protected EasyPostService $easyPostService;

    public function __construct(EasyPostService $easyPostService, private readonly ShipmentTrackingService $tracking)
    {
        $this->easyPostService = $easyPostService;
    }

    #[OA\Post(
        path: '/api/v1/admin/orders/{order}/label',
        summary: 'Purchase Shipping Label',
        description: 'Idempotently purchase a shipping label for a paid processing order. /ship remains a deprecated API v1 alias.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Shipping']
    )]
    #[OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'rate_id', type: 'string', nullable: true, example: 'rate_123456', description: 'Defaults to the rate selected at checkout'),
                new OA\Property(property: 'shipment_id', type: 'string', nullable: true, deprecated: true, example: 'shp_123456'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Order shipped successfully.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Order shipped successfully.'),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'status', type: 'string', example: 'shipped'),
                        new OA\Property(property: 'easypost_shipment_id', type: 'string', example: 'shp_123456'),
                        new OA\Property(property: 'tracking_number', type: 'string', example: '9400110898825022567544'),
                        new OA\Property(property: 'label_url', type: 'string', example: 'https://easypost-files.s3-us-west-2.amazonaws.com/files/postage_label/...'),
                    ]
                ),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
            ]
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Failed to purchase shipping label.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Failed to purchase shipping label.'),
                new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                new OA\Property(
                    property: 'errors',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'shipping',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            example: ['Order status must be processing or pending to ship.']
                        ),
                    ]
                ),
            ]
        )
    )]
    public function createShipment(Request $request, int $orderId): JsonResponse
    {
        $request->validate([
            'rate_id' => ['nullable', 'string'],
            'shipment_id' => ['nullable', 'string'],
        ]);

        $order = Order::findOrFail($orderId);

        if ($order->tracking_number && $order->label_url) {
            return $this->shipmentResponse($order, 'Shipping label already exists.');
        }

        // Check if order is eligible for shipping
        if ($order->status !== 'processing' || ! $order->isPaid()) {
            return $this->error('Only paid processing orders can be shipped.', 409, [
                'status' => ['Order must be paid and in processing status.'],
            ]);
        }

        $shipmentId = $request->input('shipment_id') ?: $order->easypost_shipment_id;
        $rateId = $request->input('rate_id') ?: $order->shipping_rate_id;

        if (! $shipmentId || ! $rateId) {
            return $this->error('The order does not have a usable shipping quote.', 422, [
                'shipping' => ['Request new shipping rates for this order.'],
            ]);
        }

        if ($order->easypost_shipment_id && $shipmentId !== $order->easypost_shipment_id) {
            return $this->error('The selected shipment does not belong to this order.', 422);
        }

        if ($order->shipping_rate_id && $rateId !== $order->shipping_rate_id) {
            return $this->error('The selected rate does not belong to this order.', 422);
        }

        try {
            if (! $order->shipping_rate_id) {
                $rate = $this->easyPostService->retrieveRate($rateId);
                if (($rate->shipment_id ?? null) !== $shipmentId) {
                    return $this->error('The selected rate does not belong to this shipment.', 422);
                }
            }

            $boughtShipment = $this->easyPostService->purchaseLabel($shipmentId, $rateId);

            // Update the order in a transaction
            $updatedOrder = DB::transaction(function () use ($order, $boughtShipment, $shipmentId, $rateId) {
                $order->update([
                    'easypost_shipment_id' => $shipmentId,
                    'shipping_rate_id' => $rateId,
                    'tracking_number' => $boughtShipment->tracking_code,
                    'label_url' => $boughtShipment->postage_label->label_url,
                    'tracking_url' => $boughtShipment->tracker->public_url ?? null,
                    'shipment_status' => $boughtShipment->tracker->status ?? 'pre_transit',
                    'shipped_at' => now(),
                    'estimated_delivery' => isset($boughtShipment->tracker->est_delivery_date)
                        ? Carbon::parse($boughtShipment->tracker->est_delivery_date)->toDateString()
                        : null,
                    'status' => 'shipped',
                ]);

                return $order->refresh();
            });

            if (isset($boughtShipment->tracker)) {
                $updatedOrder = $this->tracking->sync($updatedOrder, $boughtShipment->tracker);
            }

            return $this->shipmentResponse($updatedOrder, 'Order shipped successfully.');

        } catch (Exception $e) {
            Log::error('Admin Shipping createShipment failed: '.$e->getMessage());

            return $this->error('Shipping label could not be purchased.', 422, [
                'shipping' => ['Check the carrier balance, address, and selected rate.'],
            ]);
        }
    }

    #[OA\Get(
        path: '/api/v1/admin/orders/{order}/tracking',
        summary: 'Get Order Tracking Info',
        description: 'Get real-time tracking details from EasyPost for a shipped order.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Shipping']
    )]
    #[OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Tracking info retrieved successfully.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Tracking info retrieved.'),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'tracking_code', type: 'string', example: 'EZ1000000001'),
                        new OA\Property(property: 'status', type: 'string', example: 'in_transit'),
                        new OA\Property(property: 'status_detail', type: 'string', example: 'en_route'),
                        new OA\Property(property: 'weight', type: 'number', example: 10),
                        new OA\Property(property: 'est_delivery_date', type: 'string', nullable: true, example: '2026-06-05T13:00:00Z'),
                        new OA\Property(
                            property: 'tracking_details',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'message', type: 'string', example: 'Billing information received'),
                                    new OA\Property(property: 'status', type: 'string', example: 'unknown'),
                                    new OA\Property(property: 'datetime', type: 'string', example: '2026-06-01T12:00:00Z'),
                                    new OA\Property(property: 'city', type: 'string', example: 'San Francisco'),
                                    new OA\Property(property: 'state', type: 'string', example: 'CA'),
                                ]
                            )
                        ),
                    ]
                ),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
            ]
        )
    )]
    public function getTracking(int $orderId): JsonResponse
    {
        $order = Order::findOrFail($orderId);

        if (! $order->tracking_number) {
            return $this->error('Order has not been shipped yet or does not have a tracking number.', 422, [
                'tracking' => ['No tracking number associated with this order.'],
            ]);
        }

        try {
            $shipment = $this->easyPostService->retrieveShipment($order->easypost_shipment_id);

            $tracker = $shipment->tracker;

            if (! $tracker) {
                return $this->error('No tracking details available yet for this shipment.', 422, [
                    'tracking' => ['Tracker has not been generated by carrier yet.'],
                ]);
            }

            $order = $this->tracking->sync($order, $tracker);

            $trackingDetails = [];
            if (isset($tracker->tracking_details)) {
                foreach ($tracker->tracking_details as $detail) {
                    $trackingDetails[] = [
                        'message' => $detail->message,
                        'status' => $detail->status,
                        'datetime' => $detail->datetime,
                        'city' => $detail->tracking_location->city ?? null,
                        'state' => $detail->tracking_location->state ?? null,
                    ];
                }
            }

            return $this->success([
                'tracking_code' => $tracker->tracking_code,
                'status' => $tracker->status,
                'status_detail' => $tracker->status_detail ?? null,
                'weight' => $tracker->weight ?? null,
                'est_delivery_date' => $tracker->est_delivery_date ?? null,
                'tracking_details' => $trackingDetails,
                'events' => $order->tracking_events ?? [],
            ], 'Tracking info retrieved.');

        } catch (Exception $e) {
            Log::error('Admin Shipping getTracking failed: '.$e->getMessage());

            return $this->error('Failed to retrieve tracking info.', 422, [
                'tracking' => ['Tracking provider request failed.'],
            ]);
        }
    }

    private function success($data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }

    private function error(string $message, int $status, $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }

    private function shipmentResponse(Order $order, string $message): JsonResponse
    {
        return $this->success([
            'id' => $order->id,
            'status' => $order->status,
            'easypost_shipment_id' => $order->easypost_shipment_id,
            'tracking_number' => $order->tracking_number,
            'label_url' => $order->label_url,
            'shipment' => [
                'tracking_number' => $order->tracking_number,
                'carrier' => $order->shipping_carrier,
                'service' => $order->shipping_service,
                'tracking_url' => $order->tracking_url,
                'label_url' => $order->label_url,
                'shipped_at' => $order->shipped_at?->toIso8601String(),
                'estimated_delivery' => $order->estimated_delivery?->format('Y-m-d'),
                'status' => $order->shipment_status ?? 'unknown',
            ],
        ], $message);
    }
}
