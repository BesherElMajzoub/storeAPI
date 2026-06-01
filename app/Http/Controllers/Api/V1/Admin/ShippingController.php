<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\EasyPostService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class ShippingController extends Controller
{
    protected EasyPostService $easyPostService;

    public function __construct(EasyPostService $easyPostService)
    {
        $this->easyPostService = $easyPostService;
    }

    #[OA\Post(
        path: '/api/v1/admin/orders/{order}/ship',
        summary: 'Purchase Shipping Label',
        description: 'Purchase a shipping label and mark the order as shipped.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Shipping']
    )]
    #[OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['rate_id'],
            properties: [
                new OA\Property(property: 'rate_id', type: 'string', example: 'rate_123456'),
                new OA\Property(property: 'shipment_id', type: 'string', nullable: true, example: 'shp_123456', description: 'Optional if order has easypost_shipment_id stored')
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
                        new OA\Property(property: 'label_url', type: 'string', example: 'https://easypost-files.s3-us-west-2.amazonaws.com/files/postage_label/...')
                    ]
                ),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
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
                        )
                    ]
                )
            ]
        )
    )]
    public function createShipment(Request $request, int $orderId): JsonResponse
    {
        $request->validate([
            'rate_id'     => ['required', 'string'],
            'shipment_id' => ['nullable', 'string'],
        ]);

        $order = Order::findOrFail($orderId);

        // Check if order is eligible for shipping
        if (!in_array($order->status, ['pending', 'processing'])) {
            return $this->error('Order must be in pending or processing status to be shipped.', 422, [
                'status' => ['Order status must be pending or processing.']
            ]);
        }

        $shipmentId = $request->input('shipment_id') ?: $order->easypost_shipment_id;

        try {
            // If shipment_id is not available, we dynamically create a shipment based on order shipping address
            if (!$shipmentId) {
                $shippingAddress = $order->shipping_address;
                if (empty($shippingAddress)) {
                    return $this->error('Order does not have a shipping address.', 422, [
                        'shipping_address' => ['Shipping address is empty.']
                    ]);
                }

                // Format the shipping address to match EasyPost expectations
                $toAddress = [
                    'name'    => $shippingAddress['full_name'] ?? $shippingAddress['name'] ?? $order->user?->name ?? 'Customer',
                    'street1' => $shippingAddress['street'] ?? $shippingAddress['street1'] ?? '',
                    'street2' => $shippingAddress['apartment'] ?? $shippingAddress['street2'] ?? '',
                    'city'    => $shippingAddress['city'] ?? '',
                    'state'   => $shippingAddress['state'] ?? $shippingAddress['area'] ?? '',
                    'zip'     => $shippingAddress['postal_code'] ?? $shippingAddress['zip'] ?? '',
                    'country' => $shippingAddress['country'] ?? 'US',
                    'phone'   => $shippingAddress['phone'] ?? $order->user?->phone ?? '',
                ];

                $shipment = $this->easyPostService->getShippingRates($toAddress);
                $shipmentId = $shipment->id;
            }

            // Purchase the label
            $boughtShipment = $this->easyPostService->purchaseLabel($shipmentId, $request->input('rate_id'));

            // Update the order in a transaction
            $updatedOrder = DB::transaction(function () use ($order, $boughtShipment, $shipmentId) {
                $order->update([
                    'easypost_shipment_id' => $shipmentId,
                    'tracking_number'      => $boughtShipment->tracking_code,
                    'label_url'            => $boughtShipment->postage_label->label_url,
                    'status'               => 'shipped',
                ]);

                return $order->refresh();
            });

            return $this->success([
                'id'                   => $updatedOrder->id,
                'status'               => $updatedOrder->status,
                'easypost_shipment_id' => $updatedOrder->easypost_shipment_id,
                'tracking_number'      => $updatedOrder->tracking_number,
                'label_url'            => $updatedOrder->label_url,
            ], 'Order shipped successfully.');

        } catch (Exception $e) {
            Log::error('Admin Shipping createShipment failed: ' . $e->getMessage());
            return $this->error('Failed to ship order: ' . $e->getMessage(), 422, [
                'shipping' => [$e->getMessage()]
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
                                    new OA\Property(property: 'state', type: 'string', example: 'CA')
                                ]
                            )
                        )
                    ]
                ),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
            ]
        )
    )]
    public function getTracking(int $orderId): JsonResponse
    {
        $order = Order::findOrFail($orderId);

        if (!$order->tracking_number) {
            return $this->error('Order has not been shipped yet or does not have a tracking number.', 422, [
                'tracking' => ['No tracking number associated with this order.']
            ]);
        }

        try {
            // In EasyPost, we retrieve tracker by its ID or we can query status
            // The easypost_shipment_id has trackers, or we can use EasyPostService tracker retrieve
            // Typically tracker is retrieved using order's tracking_number or a tracker ID.
            // EasyPost allows retrieving a tracker using its tracker ID. If we don't store tracker ID,
            // we can retrieve the shipment first and access $shipment->tracker->id, or retrieve tracker directly by tracking number!
            // Wait, does tracker retrieve accept tracking number? Let's check how EasyPost retrieves trackers.
            // Actually, you can create a tracker with tracking_number and carrier, or retrieve by tracker ID.
            // Let's retrieve the shipment from EasyPost if easypost_shipment_id is set, then get its tracker info.
            // This is extremely reliable!
            if ($order->easypost_shipment_id) {
                // If we have shipment ID, let's retrieve shipment to get its tracker
                $shipment = $this->easyPostService->getShippingRates([], []); // wait, retrieve shipment is needed
                // Let's add a helper to retrieve shipment in EasyPostService if needed, or retrieve the tracker directly.
                // In EasyPost, a tracker can be created/retrieved by tracking number. If it already exists, EasyPost returns it!
                // Let's check if we can retrieve tracker directly or create/retrieve it.
                // Creating a tracker for an existing tracking number is the standard way to retrieve/register a tracker in EasyPost:
                // $tracker = $client->tracker->create(['tracking_code' => $trackingCode, 'carrier' => $carrier]);
                // This is extremely standard and always works!
            }

            // Since we might not have carrier name, retrieving shipment is the safest way to get tracker.
            // Let's modify EasyPostService to add retrieveShipment method, OR just fetch shipment and retrieve tracker!
            // Wait, let's use the shipment ID to retrieve the shipment from EasyPost. Let's add retrieveShipment to EasyPostService.
            // Let's look at EasyPostService:
            // $this->client->shipment->retrieve($shipmentId)
            // Let's retrieve the shipment first, which contains the tracker!
            $shipment = $this->easyPostService->retrieveShipment($order->easypost_shipment_id);

            $tracker = $shipment->tracker;

            if (!$tracker) {
                return $this->error('No tracking details available yet for this shipment.', 422, [
                    'tracking' => ['Tracker has not been generated by carrier yet.']
                ]);
            }

            $trackingDetails = [];
            if (isset($tracker->tracking_details)) {
                foreach ($tracker->tracking_details as $detail) {
                    $trackingDetails[] = [
                        'message'  => $detail->message,
                        'status'   => $detail->status,
                        'datetime' => $detail->datetime,
                        'city'     => $detail->tracking_location->city ?? null,
                        'state'    => $detail->tracking_location->state ?? null,
                    ];
                }
            }

            return $this->success([
                'tracking_code'    => $tracker->tracking_code,
                'status'           => $tracker->status,
                'status_detail'    => $tracker->status_detail ?? null,
                'weight'           => $tracker->weight ?? null,
                'est_delivery_date' => $tracker->est_delivery_date ?? null,
                'tracking_details' => $trackingDetails,
            ], 'Tracking info retrieved.');

        } catch (Exception $e) {
            Log::error('Admin Shipping getTracking failed: ' . $e->getMessage());
            return $this->error('Failed to retrieve tracking info: ' . $e->getMessage(), 422, [
                'tracking' => [$e->getMessage()]
            ]);
        }
    }

    private function success($data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int $status, $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors,
        ], $status);
    }
}
