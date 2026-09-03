<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendAdminAlert;
use App\Mail\OrderPaidMail;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use OpenApi\Attributes as OA;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    #[OA\Post(
        path: '/api/v1/webhooks/stripe',
        summary: 'Stripe Webhook',
        description: 'Receives Stripe webhook events. Verifies signature. Handles: `checkout.session.completed`, `checkout.session.expired`, `charge.refunded`. **No authentication required** — secured by Stripe signature.',
        tags: ['Webhooks']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(type: 'object'))
    )]
    #[OA\Response(response: 200, description: 'Webhook handled')]
    #[OA\Response(response: 400, description: 'Invalid signature or payload')]
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        if (! $secret) {
            Log::critical('Stripe webhook secret is not configured.');

            return response()->json(['message' => 'Webhook is not configured.'], 503);
        }

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

        $handled = match ($event->type) {
            'checkout.session.completed' => $this->handleSessionCompleted($event),
            'checkout.session.expired' => tap(true, fn () => $this->handleSessionExpired($event)),
            'charge.refunded' => tap(true, fn () => $this->handleChargeRefunded($event)),
            default => true,
        };

        if (! $handled) {
            return response()->json(['message' => 'Webhook data does not match the order.'], 422);
        }

        return response()->json(['message' => 'Webhook received.']);
    }

    // ── Event Handlers ────────────────────────────────────────────────────────

    private function handleSessionCompleted(Event $event): bool
    {
        $session = $event->data->object;
        $orderId = $session->metadata->order_id ?? null;

        if (! $orderId) {
            Log::warning('Stripe webhook: checkout.session.completed missing order_id metadata');

            return false;
        }

        $order = Order::find($orderId);
        if (! $order) {
            Log::warning("Stripe webhook: order {$orderId} not found");

            return false;
        }

        $expectedAmount = (int) round((float) $order->total * 100);
        $amountMatches = (int) ($session->amount_total ?? -1) === $expectedAmount;
        $currencyMatches = strtolower((string) ($session->currency ?? '')) === config('services.stripe.currency', 'usd');
        $sessionMatches = (string) $session->id === (string) $order->stripe_session_id;

        if (! $amountMatches || ! $currencyMatches || ! $sessionMatches) {
            Log::warning('Stripe webhook order verification failed.', [
                'order_id' => $order->id,
                'session_matches' => $sessionMatches,
                'amount_matches' => $amountMatches,
                'currency_matches' => $currencyMatches,
            ]);

            return false;
        }

        // Idempotency: skip if already paid
        if ($order->isPaid()) {
            return true;
        }

        $order->update([
            'status' => 'processing',
            'payment_status' => 'paid',
            'stripe_payment_intent_id' => $session->payment_intent,
            'paid_at' => now(),
        ]);

        $order->load('items');
        $itemCount = $order->items->sum('quantity');
        $message = "🛒 New order {$order->order_number} — \${$order->total} — {$itemCount} items";
        SendAdminAlert::dispatch($message)->onQueue('notifications');
        Mail::to($order->user->email)->queue(new OrderPaidMail($order));

        Log::info("Order {$order->order_number} marked as paid via Stripe.");

        return true;
    }

    private function handleSessionExpired(Event $event): void
    {
        $session = $event->data->object;
        $orderId = $session->metadata->order_id ?? null;

        if (! $orderId) {
            return;
        }

        $order = Order::find($orderId);
        if (! $order) {
            return;
        }

        if ((string) $session->id !== (string) $order->stripe_session_id) {
            Log::warning('Stripe expired-session webhook did not match the order session.', [
                'order_id' => $order->id,
            ]);

            return;
        }

        // Idempotency: skip if already decided
        if (in_array($order->status, ['processing', 'cancelled', 'refunded'], true)) {
            return;
        }

        $order->update([
            'status' => 'cancelled',
            'payment_status' => 'failed',
            'cancelled_at' => now(),
        ]);

        Log::info("Order {$order->order_number} cancelled due to expired Stripe session.");
    }

    private function handleChargeRefunded(Event $event): void
    {
        $charge = $event->data->object;
        $paymentIntentId = $charge->payment_intent ?? null;

        if (! $paymentIntentId) {
            return;
        }

        $order = Order::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (! $order) {
            return;
        }

        // Idempotency: skip if already refunded
        if ($order->isRefunded()) {
            return;
        }

        $refundedAmount = round(((int) ($charge->amount_refunded ?? 0)) / 100, 2);
        $isFullRefund = $refundedAmount >= (float) $order->total;

        $order->update(array_filter([
            'refunded_amount' => $refundedAmount,
            'status' => $isFullRefund ? 'refunded' : null,
            'payment_status' => $isFullRefund ? 'refunded' : null,
            'refunded_at' => $isFullRefund ? now() : null,
        ], fn ($value) => $value !== null));

        Log::info("Stripe refund recorded for order {$order->order_number}.", [
            'amount' => $refundedAmount,
            'full_refund' => $isFullRefund,
        ]);
    }
}
