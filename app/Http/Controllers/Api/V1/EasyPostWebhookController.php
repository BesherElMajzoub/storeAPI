<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendAdminAlert;
use App\Models\Order;
use App\Services\EasyPostService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class EasyPostWebhookController extends Controller
{
    protected EasyPostService $easyPostService;

    public function __construct(EasyPostService $easyPostService)
    {
        $this->easyPostService = $easyPostService;
    }

    #[OA\Post(
        path: '/api/v1/webhooks/easypost',
        summary: 'EasyPost Webhook Receiver',
        description: 'Receives and handles real-time tracking updates from EasyPost webhook service.',
        tags: ['Webhooks']
    )]
    #[OA\Response(
        response: 200,
        description: 'Webhook processed successfully.'
    )]
    #[OA\Response(
        response: 401,
        description: 'Invalid signature.'
    )]
    public function handle(Request $request): JsonResponse
    {
        if (! config('services.easypost.webhook_secret')) {
            Log::critical('EasyPost webhook secret is not configured.');

            return response()->json([
                'success' => false,
                'message' => 'Webhook is not configured.',
            ], 503);
        }

        $payload = $request->getContent();
        $headers = $request->headers->all();

        try {
            $event = $this->easyPostService->validateWebhook($payload, $headers);

            // Handle the event
            $description = $event->description ?? '';
            $result = $event->result ?? null;

            Log::info("Received EasyPost Webhook: {$description}");

            if ($description === 'tracker.updated' && $result) {
                $trackingCode = $result->tracking_code ?? '';
                $trackerStatus = $result->status ?? '';

                if ($trackingCode) {
                    $order = Order::where('tracking_number', $trackingCode)->first();

                    if ($order) {
                        Log::info("Updating tracking for Order #{$order->order_number} to status: {$trackerStatus}");

                        if ($trackerStatus === 'delivered' && $order->status !== 'delivered') {
                            $order->status = 'delivered';
                            $order->save();

                            SendAdminAlert::dispatch("🎉 Order #{$order->order_number} has been DELIVERED successfully! Tracking: {$order->tracking_number}");
                        } elseif (in_array($trackerStatus, ['failure', 'return_to_sender'])) {
                            SendAdminAlert::dispatch("⚠️ EasyPost Alert: Order #{$order->order_number} shipping status marked as: {$trackerStatus}. Tracking: {$order->tracking_number}");
                        } else {
                            // Any other status (in_transit, out_for_delivery, pre_transit)
                            // Transition pending/processing to shipped
                            if (in_array($order->status, ['pending', 'processing'])) {
                                $order->status = 'shipped';
                                $order->save();
                                SendAdminAlert::dispatch("🚚 Order #{$order->order_number} is now {$trackerStatus}. Tracking: {$order->tracking_number}");
                            }
                        }
                    } else {
                        Log::warning("Order not found for tracking number: {$trackingCode}");
                    }
                }
            }

            return response()->json(['success' => true, 'message' => 'Event processed successfully.']);

        } catch (Exception $e) {
            Log::error('EasyPost Webhook processing error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Webhook validation failed.',
            ], 401);
        }
    }
}
