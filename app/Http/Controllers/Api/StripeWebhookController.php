<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendAdminAlert;
use App\Mail\OrderConfirmedMail;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderPaymentService;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
        description: 'Receives Stripe webhook events. Verifies signature. Handles: `checkout.session.completed`, `checkout.session.expired`, `payment_intent.canceled`, `charge.refunded`, `refund.created`, `refund.updated`, `refund.failed` (also `charge.refund.updated`). Each event is recorded once by its Stripe event id. **No authentication required** — secured by Stripe signature.',
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
            'refund.created', 'refund.updated', 'refund.failed', 'charge.refund.updated' => tap(true, fn () => $this->handleRefundUpdated($event)),
            'payment_intent.canceled' => tap(true, fn () => $this->handlePaymentIntentCanceled($event)),
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
        $sessionData = $session->toArray();
        $paymentIntentId = isset($sessionData['payment_intent'])
            ? (string) $sessionData['payment_intent']
            : null;

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

        // Sessions created with manual capture only hold the card; the money
        // is captured later by OrderPaymentService::capture().
        $isAuthorization = ($session->metadata->capture_method ?? null) === 'manual';

        $result = DB::transaction(function () use ($order, $paymentIntentId, $session, $isAuthorization): array|bool|null {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $expectedAmount = (int) round((float) $lockedOrder->total * 100);
            $amountMatches = (int) ($session->amount_total ?? -1) === $expectedAmount;
            $currencyMatches = strtolower((string) ($session->currency ?? '')) === config('services.stripe.currency', 'usd');
            $sessionMatches = (string) $session->id === (string) $lockedOrder->stripe_session_id;

            if (! $amountMatches || ! $currencyMatches || ! $sessionMatches) {
                return false;
            }

            // Idempotency: only the transaction that moves the order to paid sends side effects.
            if ($lockedOrder->isPaid() || $lockedOrder->isAuthorized()) {
                return null;
            }

            $updated = Order::query()
                ->whereKey($lockedOrder->id)
                ->where('status', 'pending_payment')
                ->where('payment_status', 'unpaid')
                ->update([
                    'status' => 'processing',
                    'payment_status' => $isAuthorization ? 'authorized' : 'paid',
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'authorized_at' => now(),
                    'paid_at' => $isAuthorization ? null : now(),
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                $isLateCancelledPayment = (string) $lockedOrder->getRawOriginal('status') === 'cancelled'
                    && in_array((string) $lockedOrder->getRawOriginal('payment_status'), ['unpaid', 'failed', 'voided'], true);

                // refund_status other than 'none' means the money is already
                // being (or has been) returned, so this is a replay.
                if (! $isLateCancelledPayment || $lockedOrder->refund_status !== 'none') {
                    return null;
                }

                $payment = Payment::firstOrNew(['order_id' => $lockedOrder->id]);
                $alreadyRequiresRefund = $payment->exists && $payment->getRawOriginal('status') === 'requires_refund'
                    && $payment->transaction_id === $paymentIntentId;

                $lockedOrder->update(['stripe_payment_intent_id' => $paymentIntentId]);
                $payment->fill([
                    'transaction_id' => $paymentIntentId,
                    'payment_provider' => 'stripe',
                    'status' => 'requires_refund',
                    'amount' => $lockedOrder->total,
                ])->save();

                app(OrderPaymentService::class)->queueSettlement($lockedOrder);

                return ['state' => 'requires_refund', 'notify' => ! $alreadyRequiresRefund, 'order' => $lockedOrder->fresh()];
            }

            $lockedOrder->refresh();

            Payment::updateOrCreate(
                ['order_id' => $lockedOrder->id],
                [
                    'transaction_id' => $paymentIntentId,
                    'payment_provider' => 'stripe',
                    'status' => $isAuthorization ? 'pending' : 'completed',
                    'amount' => $lockedOrder->total,
                ]
            );

            return ['state' => 'paid', 'order' => $lockedOrder->fresh()];
        });

        if ($result === false) {
            return false;
        }

        if ($result === null) {
            return true;
        }

        $resultOrder = $result['order'];

        if ($result['state'] === 'requires_refund') {
            if ($result['notify']) {
                Log::critical('Stripe payment completed after the order stopped accepting payment.', [
                    'order_id' => $resultOrder->id,
                    'order_status' => $resultOrder->getRawOriginal('status'),
                    'payment_intent_id' => $paymentIntentId,
                ]);
                SendAdminAlert::dispatch("Stripe payment {$paymentIntentId} arrived for cancelled order {$resultOrder->order_number}; it is being released/refunded automatically.")
                    ->onQueue('notifications');
            }

            return true;
        }

        $order = $resultOrder;

        $itemCount = (int) $order->items()->sum('quantity');
        $message = "🛒 New order {$order->order_number} — \${$order->total} — {$itemCount} items";
        SendAdminAlert::dispatch($message)->onQueue('notifications');
        Mail::to($order->user()->value('email'))->queue(new OrderConfirmedMail($order));

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

        $cancelledOrder = DB::transaction(function () use ($orderId, $session): ?Order {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if (! $order) {
                return null;
            }

            $order->refresh();

            if ((string) $session->id !== (string) $order->stripe_session_id) {
                return null;
            }

            if ((string) $order->getRawOriginal('status') !== 'pending_payment'
                || (string) $order->getRawOriginal('payment_status') !== 'unpaid') {
                return null;
            }

            $order->update([
                'status' => 'cancelled',
                'payment_status' => 'voided',
                'cancelled_at' => now(),
            ]);

            return $order;
        });

        if ($cancelledOrder) {
            Log::info("Order {$cancelledOrder->order_number} cancelled due to expired Stripe session.");
        }
    }

    private function handleChargeRefunded(Event $event): void
    {
        $charge = $event->data->object;
        $paymentIntentId = $charge->payment_intent ?? null;

        if (! $paymentIntentId) {
            return;
        }

        $order = Order::query()->where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (! $order) {
            return;
        }

        // Only a refund the shop did not start shows up here as new money; the
        // refund record decides, so a refund still pending is never marked paid out early.
        app(RefundService::class)->recordChargeRefunded($order, (int) ($charge->amount_refunded ?? 0), $event->id ?? null);
    }

    /**
     * A card hold was cancelled in Stripe (expired, or cancelled in the
     * dashboard) before the app captured it: put the order on hold. Holds
     * the app releases itself belong to cancelled orders and are ignored.
     */
    private function handlePaymentIntentCanceled(Event $event): void
    {
        $intentId = $event->data->object->id ?? null;
        if (! $intentId) {
            return;
        }

        $order = Order::query()->where('stripe_payment_intent_id', $intentId)
            ->where('status', 'processing')->where('payment_status', 'authorized')->first();

        if ($order) {
            app(OrderPaymentService::class)->markCaptureFailed($order, 'the card hold was cancelled in Stripe');
        }
    }

    /**
     * refund.created / refund.updated / refund.failed (and the older
     * charge.refund.updated): move our attempt to what Stripe reports. A
     * success settles a pending refund; a failure puts the payment back to
     * paid and tells the owner what to do next.
     */
    private function handleRefundUpdated(Event $event): void
    {
        $refund = $event->data->object;
        $paymentIntentId = $refund->payment_intent ?? null;

        if (! $paymentIntentId) {
            return;
        }

        $order = Order::query()->where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (! $order) {
            return;
        }

        // refund.failed always means failed, whatever the object says.
        if ($event->type === 'refund.failed' && ($refund->status ?? null) !== 'failed') {
            $refund = (object) array_merge($refund->toArray(), ['status' => 'failed']);
        }

        app(RefundService::class)->recordStripeRefund($order, $refund, $event->id ?? null);
    }
}
