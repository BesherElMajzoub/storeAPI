<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Services\StripeCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use OpenApi\Attributes as OA;

class StripeWebhookController extends Controller
{
    #[OA\Post(
        path: "/api/v1/webhooks/stripe",
        summary: "Stripe Webhook",
        description: "Receives Stripe webhook events. Verifies signature. Handles: `checkout.session.completed`, `checkout.session.expired`, `charge.refunded`. **No authentication required** — secured by Stripe signature.",
        tags: ["Webhooks"]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(mediaType: "application/json", schema: new OA\Schema(type: "object"))
    )]
    #[OA\Response(response: 200, description: "Webhook handled")]
    #[OA\Response(response: 400, description: "Invalid signature or payload")]
    public function handle(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret    = config('services.stripe.webhook_secret');

        // Verify signature
        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed.', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Invalid signature.'], 400);
        } catch (\UnexpectedValueException $e) {
            Log::warning('Stripe webhook malformed payload.', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        match ($event->type) {
            'checkout.session.completed' => $this->handleSessionCompleted($event),
            'checkout.session.expired'   => $this->handleSessionExpired($event),
            'charge.refunded'            => $this->handleChargeRefunded($event),
            default                      => null,
        };

        return response()->json(['message' => 'Webhook received.']);
    }

    // ── Event Handlers ────────────────────────────────────────────────────────

    private function handleSessionCompleted(Event $event): void
    {
        $session = $event->data->object;
        $orderId = $session->metadata->order_id ?? null;

        if (!$orderId) {
            Log::warning('Stripe webhook: checkout.session.completed missing order_id metadata');
            return;
        }

        $order = Order::find($orderId);
        if (!$order) {
            Log::warning("Stripe webhook: order {$orderId} not found");
            return;
        }

        // Idempotency: skip if already paid
        if ($order->isPaid()) {
            return;
        }

        $order->update([
            'status'                     => 'processing',
            'payment_status'             => 'paid',
            'stripe_payment_intent_id'   => $session->payment_intent,
            'paid_at'                    => now(),
        ]);

        Log::info("Order {$order->order_number} marked as paid via Stripe.");
    }

    private function handleSessionExpired(Event $event): void
    {
        $session = $event->data->object;
        $orderId = $session->metadata->order_id ?? null;

        if (!$orderId) {
            return;
        }

        $order = Order::find($orderId);
        if (!$order) {
            return;
        }

        // Idempotency: skip if already decided
        if (in_array($order->status, ['processing', 'cancelled', 'refunded'], true)) {
            return;
        }

        $order->update([
            'status'          => 'cancelled',
            'payment_status'  => 'failed',
            'cancelled_at'    => now(),
        ]);

        Log::info("Order {$order->order_number} cancelled due to expired Stripe session.");
    }

    private function handleChargeRefunded(Event $event): void
    {
        $charge          = $event->data->object;
        $paymentIntentId = $charge->payment_intent ?? null;

        if (!$paymentIntentId) {
            return;
        }

        $order = Order::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (!$order) {
            return;
        }

        // Idempotency: skip if already refunded
        if ($order->isRefunded()) {
            return;
        }

        $order->update([
            'status'          => 'refunded',
            'payment_status'  => 'refunded',
            'refunded_at'     => now(),
        ]);

        Log::info("Order {$order->order_number} marked as refunded via Stripe.");
    }
}
