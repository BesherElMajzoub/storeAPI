<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\RefundNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BulkUpdateOrderStatusRequest;
use App\Http\Requests\Api\V1\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\AdminOrderResource;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RefundAttempt;
use App\Services\OrderPaymentService;
use App\Services\StripeCheckoutService;
use App\Support\IdempotentRequest;
use App\Support\RefundAdvice;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/orders',
        summary: 'Admin List Orders',
        description: 'List all orders with filtering',
        security: [['bearerAuth' => []]],
        tags: ['Admin Orders']
    )]
    #[OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'payment_status', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'user_id', in: 'query', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'refund_action', in: 'query', description: '`needed` = the refund failed and waits for an admin (retry, top up, refund by Zelle or PayPal, or check).', schema: new OA\Schema(type: 'string', enum: ['needed']))]
    #[OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(
        response: 200,
        description: 'Orders fetched',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Order')
                        ),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/UnauthorizedResponse')]
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $orders = Order::query()
            ->with(['user', 'items.product.media', 'items.variant', 'payment', 'cancellationRequest', 'refundAttempts'])
            ->when($request->filled('status'), fn ($q) => $q->status($request->input('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->paymentStatus($request->input('payment_status')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', (int) $request->input('user_id')))
            ->when($request->filled('search'), fn ($q) => $q->search($request->input('search')))
            ->when($request->input('refund_action') === 'needed', fn ($q) => $q->refundNeedsAction())
            ->dateRange($request->input('date_from'), $request->input('date_to'))
            ->latest()
            ->paginate($perPage);

        return $this->success(
            AdminOrderResource::collection($orders)->response()->getData(true),
            'Orders fetched.'
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/orders/{id}',
        summary: 'Admin Show Order',
        description: 'Show details of a specific order',
        security: [['bearerAuth' => []]],
        tags: ['Admin Orders']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Order fetched',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Order'),
            ]
        )
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function show($id)
    {
        $order = Order::with(['items', 'user', 'payment', 'cancellationRequest', 'refundAttempts.creator', 'refundAttempts.events'])->findOrFail($id);

        return $this->success(new AdminOrderResource($order), 'Order fetched.');
    }

    #[OA\Post(
        path: '/api/v1/admin/orders/bulk-status',
        summary: 'Atomically update the status of multiple orders',
        security: [['bearerAuth' => []]],
        tags: ['Admin Orders']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['ids', 'status'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', maxItems: 100, items: new OA\Items(type: 'integer')),
                new OA\Property(property: 'status', type: 'string', enum: ['pending', 'pending_payment', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded']),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'All order statuses updated')]
    #[OA\Response(response: 409, description: 'At least one transition is invalid; no orders changed. Bulk cannot cancel authorized/paid orders, and bulk shipped requires a captured payment and a tracking number on every order.')]
    public function bulkUpdateStatus(BulkUpdateOrderStatusRequest $request, StripeCheckoutService $stripe): JsonResponse
    {
        $validated = $request->validated();

        $preflightOrders = Order::query()->whereKey($validated['ids'])->get();
        $preflightErrors = [];
        foreach (collect($validated['ids'])->diff($preflightOrders->pluck('id')) as $missingId) {
            $preflightErrors[(string) $missingId] = ['Order was not found.'];
        }
        foreach ($preflightOrders as $order) {
            if (! $this->canTransition($order->status, $validated['status'], $this->statusTransitions())) {
                $preflightErrors[(string) $order->id] = [
                    "Cannot transition order from {$order->status} to {$validated['status']}.",
                ];
            } elseif ($refusal = $this->bulkRefusal($order, $validated['status'])) {
                $preflightErrors[(string) $order->id] = [$refusal];
            }
        }
        if ($preflightErrors !== []) {
            return $this->error('Status transition not allowed; no orders were changed.', 409, [
                'orders' => $preflightErrors,
            ]);
        }

        $ordersToExpire = Order::query()->whereKey($validated['ids'])
            ->where('status', 'pending_payment')->get();

        foreach ($ordersToExpire as $order) {
            if ($validated['status'] !== 'cancelled') {
                continue;
            }

            try {
                if (! $this->expirePendingPaymentSession($order, $stripe)) {
                    return $this->error('A Stripe Checkout Session has already completed; no orders were changed.', 409);
                }
            } catch (\Throwable $e) {
                Log::error('Unable to expire Stripe Checkout Session before bulk cancellation.', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);

                return $this->error('Payment provider error. No orders were changed.', 502);
            }
        }

        $outcome = DB::transaction(function () use ($validated): array {
            $orders = Order::query()
                ->whereKey($validated['ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $errors = [];

            foreach (collect($validated['ids'])->diff($orders->pluck('id')) as $missingId) {
                $errors[(string) $missingId] = ['Order was not found.'];
            }

            foreach ($orders as $order) {
                if (! $this->canTransition($order->status, $validated['status'], $this->statusTransitions())) {
                    $errors[(string) $order->id] = [
                        "Cannot transition order from {$order->status} to {$validated['status']}.",
                    ];
                } elseif ($refusal = $this->bulkRefusal($order, $validated['status'])) {
                    $errors[(string) $order->id] = [$refusal];
                }
            }

            if ($errors !== []) {
                return ['errors' => $errors, 'orders' => collect()];
            }

            foreach ($orders as $order) {
                $attributes = ['status' => $validated['status']];
                if ($validated['status'] === 'shipped' && $order->shipped_at === null) {
                    $attributes['shipped_at'] = now();
                }
                if ($validated['status'] === 'cancelled' && $order->payment_status === 'unpaid') {
                    $attributes['payment_status'] = 'voided';
                }
                $order->update($attributes);
            }

            return [
                'errors' => [],
                'orders' => Order::query()
                    ->whereKey($validated['ids'])
                    ->with(['items.product.media', 'items.variant', 'user', 'payment', 'cancellationRequest'])
                    ->get(),
            ];
        }, 3);

        if ($outcome['errors'] !== []) {
            return $this->error('Status transition not allowed; no orders were changed.', 409, [
                'orders' => $outcome['errors'],
            ]);
        }

        $results = $outcome['orders']->map(fn (Order $order) => [
            'id' => $order->id,
            'status' => 'updated',
            'order' => new AdminOrderResource($order),
        ]);

        return $this->success($results, 'Order statuses updated atomically.');
    }

    #[OA\Post(
        path: '/api/v1/admin/orders/{id}/status',
        summary: 'Admin Update Order Status',
        description: 'Transition order status or payment status',
        security: [['bearerAuth' => []]],
        tags: ['Admin Orders']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', description: 'pending, processing, shipped, delivered, cancelled', nullable: true),
                new OA\Property(property: 'payment_status', type: 'string', description: 'unpaid or failed. Paid/refunded are controlled by verified payment flows.', nullable: true),
                new OA\Property(property: 'tracking_number', type: 'string', nullable: true, description: 'Only with status=shipped, for a parcel shipped without a purchased label. Requires shipping_carrier.'),
                new OA\Property(property: 'shipping_carrier', type: 'string', nullable: true, example: 'USPS'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Order status updated. Returns the same order resource as GET /admin/orders/{id}.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Order'),
            ]
        )
    )]
    #[OA\Response(response: 422, description: 'Marked shipped without a purchased label or a manual carrier + tracking number, or without a successful payment')]
    #[OA\Response(response: 409, description: 'Status transition not allowed')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function updateStatus(UpdateOrderStatusRequest $request, $id, StripeCheckoutService $stripe, OrderPaymentService $payments)
    {
        $order = Order::findOrFail($id);
        $data = $request->validated();

        $errors = [];
        if (array_key_exists('status', $data)) {
            if (! $this->canTransition($order->status, $data['status'], $this->statusTransitions())) {
                $errors['status'] = ['Invalid status transition.'];
            }
        }

        if (array_key_exists('payment_status', $data)) {
            if (! $this->canTransition($order->payment_status, $data['payment_status'], $this->paymentStatusTransitions())) {
                $errors['payment_status'] = ['Invalid payment status transition.'];
            }
        }

        if ($errors) {
            return $this->error('Status transition not allowed.', 409, $errors);
        }

        if (($data['status'] ?? null) === 'shipped') {
            if ($refusal = $this->shipRefusal($order, filled($data['tracking_number'] ?? null))) {
                return $this->error($refusal, 422, ['status' => [$refusal]]);
            }

            // Shipping ends the cancel window: take the held money first, as a label purchase does.
            if ($order->isAuthorized()) {
                try {
                    $captured = $payments->capture($order);
                } catch (\Throwable $e) {
                    Log::error('Payment capture before manual shipping failed.', ['order_id' => $order->id, 'error' => $e->getMessage()]);

                    return $this->error('The payment could not be captured, so the order was not marked shipped.', 502);
                }

                if (! $captured) {
                    return $this->error('The payment could not be captured, so the order was not marked shipped.', 409, [
                        'status' => ['The payment hold is no longer valid.'],
                    ]);
                }
            }
        }

        if (($data['status'] ?? null) === 'cancelled' && $order->payment_status === 'unpaid') {
            $data['payment_status'] = 'voided';
        }

        if (($data['status'] ?? null) === 'shipped' && filled($data['tracking_number'] ?? null)) {
            $data = array_merge($data, [
                'shipment_source' => 'manual',
                'shipping_service' => null,
                'shipment_status' => 'unknown',
                'tracking_url' => null,
                'estimated_delivery' => null,
                'tracking_events' => null,
                'easypost_shipment_id' => null,
                'shipping_rate_id' => null,
                'label_url' => null,
                'label_path' => null,
            ]);
        }

        if (($data['status'] ?? null) === 'cancelled' && (string) $order->getRawOriginal('status') === 'pending_payment') {
            try {
                if (! $this->expirePendingPaymentSession($order, $stripe)) {
                    return $this->error('Stripe Checkout Session has already completed; the order was not cancelled.', 409);
                }
            } catch (\Throwable $e) {
                Log::error('Unable to expire Stripe Checkout Session before cancellation.', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);

                return $this->error('Payment provider error. The order was not cancelled.', 502);
            }
        }

        $order = DB::transaction(function () use ($order, $data) {
            if (($data['status'] ?? null) === 'shipped' && $order->shipped_at === null) {
                $data['shipped_at'] = now();
            }
            if (($data['status'] ?? null) === 'cancelled') {
                $order->refundTrigger = 'admin_cancel';
                $order->refundActorId = request()->user()?->id;
            }
            $order->update($data);

            return $order->refresh()->load(['items', 'user', 'payment', 'cancellationRequest']);
        });

        if (($data['status'] ?? null) === 'shipped' && filled($data['tracking_number'] ?? null)) {
            try {
                Mail::to($order->user->email)->queue(new OrderShippedMail($order));
            } catch (\Throwable $mailError) {
                Log::error('Order manually shipped but shipment email could not be queued.', [
                    'order_id' => $order->id,
                    'error' => $mailError->getMessage(),
                ]);
            }
        }

        return $this->success(new AdminOrderResource($order), 'Order status updated.');
    }

    /**
     * Why an order cannot be marked shipped yet, or null. The customer must
     * be able to track it, and its money must be captured (or capturable).
     */
    private function shipRefusal(Order $order, bool $hasManualTracking): ?string
    {
        if ($order->fulfillment_hold) {
            return 'This order is on hold because its payment could not be captured.';
        }

        if (! $order->tracking_number && ! $hasManualTracking) {
            return 'Buy a shipping label, or enter the carrier and tracking number, before marking the order shipped.';
        }

        if (! in_array($order->payment_status, ['paid', 'authorized'], true)) {
            return 'Only orders with a successful payment can be shipped.';
        }

        return null;
    }

    /**
     * Extra rules for bulk changes. Bulk cancel never touches money (each
     * paid order needs its own confirmed refund), and bulk ship cannot
     * capture payments or take tracking numbers.
     */
    private function bulkRefusal(Order $order, string $status): ?string
    {
        if ($status === 'cancelled' && in_array($order->payment_status, ['authorized', 'paid'], true)) {
            return 'Paid orders must be cancelled one at a time from the order page.';
        }

        if ($status === 'shipped') {
            if ($order->payment_status === 'authorized') {
                return 'The payment is not captured yet; ship this order from the order page.';
            }

            return $this->shipRefusal($order, false);
        }

        return null;
    }

    private function statusTransitions(): array
    {
        return [
            'pending' => ['processing', 'cancelled'],
            'pending_payment' => ['cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['delivered', 'refunded'],
            'delivered' => ['refunded'],
            'cancelled' => [],
            'refunded' => [],
        ];
    }

    private function paymentStatusTransitions(): array
    {
        return [
            'unpaid' => ['paid', 'failed'],
            'authorized' => [],
            'paid' => ['refunded'],
            'failed' => ['paid'],
            'refunded' => [],
        ];
    }

    private function canTransition(string $from, string $to, array $map): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, $map[$from] ?? [], true);
    }

    #[OA\Post(
        path: '/api/v1/admin/orders/{order}/refund',
        summary: 'Admin Refund Order',
        description: 'Refund an order through Stripe as a **new attempt**. For a **cancelled** order this returns its money (releases the card hold or refunds the charge) and keeps the order cancelled; for a paid order it refunds it and marks it refunded. Offer it only when the order `refund.retryable` is true: a closed, expired or lost card is refused with `refund_not_retryable` and the refund goes out by Zelle or PayPal (`POST /admin/orders/{order}/refund/manual`). Send an `Idempotency-Key` header to make a double click harmless; it never reaches Stripe. An authorized-but-uncaptured order must be cancelled instead.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Orders']
    )]
    #[OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string', description: 'Required when `refund.requires_reason` is true (the refund was cancelled in the Stripe Dashboard).', nullable: true),
    ]))]
    #[OA\Response(response: 200, description: 'Order refunded')]
    #[OA\Response(response: 202, description: 'Refund accepted and awaiting Stripe confirmation')]
    #[OA\Response(response: 409, description: 'Not eligible: `refund_in_progress`, `refund_not_retryable` (with `next_action`), `nothing_owed`, or the order is not paid')]
    #[OA\Response(response: 422, description: '`reason` missing')]
    #[OA\Response(
        response: 502,
        description: 'Stripe refused the refund or could not be reached. The order keeps `refund_status = failed` with the reason; `refund.next_action` says what to do.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: false),
            new OA\Property(property: 'message', type: 'string', example: 'Stripe refund failed.'),
            new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
            new OA\Property(property: 'errors', type: 'object', properties: [
                new OA\Property(property: 'stripe_code', type: 'string', nullable: true, example: 'charge_already_refunded'),
                new OA\Property(property: 'stripe_message', type: 'string', example: 'Charge ch_123 has already been refunded.'),
                new OA\Property(property: 'next_action', type: 'string', example: 'refund_manually'),
            ]),
        ])
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function refund(Request $request, int $order, OrderPaymentService $payments): JsonResponse
    {
        $order = Order::findOrFail($order);

        return IdempotentRequest::run($request, "refund:{$order->id}", fn (): JsonResponse => $this->startRefund($request, $order, $payments));
    }

    private function startRefund(Request $request, Order $order, OrderPaymentService $payments): JsonResponse
    {
        // R2: a refund Stripe is still working on is never sent again.
        if (RefundAttempt::query()->where('order_id', $order->id)->where('status', RefundAttempt::PENDING)->exists()) {
            return $this->error('A refund for this order is already in progress.', 409, ['code' => 'refund_in_progress']);
        }

        if ($order->refund_status === 'failed') {
            $advice = RefundAdvice::forRefundState('failed', $order->refund_failure_code, $order->refund_failure_message, (int) $order->refund_failures);

            if (! $advice['retryable']) {
                return $this->error('This refund cannot be retried through Stripe.', 409, [
                    'code' => 'refund_not_retryable',
                    'next_action' => $advice['next_action'],
                ]);
            }

            if ($advice['requires_reason'] && trim((string) $request->input('reason')) === '') {
                return $this->error('A reason is required to retry this refund.', 422, ['reason' => ['A reason is required.']]);
            }
        }

        $reason = filled($request->input('reason')) ? trim((string) $request->input('reason')) : null;

        if ($order->status === 'cancelled' && $order->stripe_payment_intent_id) {
            return $this->settleCancelledOrder($order, $payments, $request->user(), $reason);
        }

        if ($order->isAuthorized()) {
            return $this->error('This payment is only authorized, not captured. Cancel the order to release it.', 409);
        }

        $order->refresh();
        $requiresRefund = Payment::query()
            ->where('order_id', $order->id)
            ->value('status') === 'requires_refund';

        if (! $order->isPaid() && ! $requiresRefund) {
            return $this->error($order->isRefunded() ? 'Order has already been refunded.' : 'Order is not paid and cannot be refunded.', 409);
        }

        if (! $order->stripe_payment_intent_id) {
            return $this->error('No Stripe PaymentIntent found for this order.', 409);
        }

        try {
            $result = $payments->refundPaidOrder(
                $order,
                $order->refund_status === 'failed' ? 'admin_retry' : 'admin_refund',
                $request->user(),
                $reason,
            );
        } catch (RefundNotAllowed $e) {
            return $this->error($e->getMessage(), $e->httpStatus, $e->toErrors());
        } catch (LockTimeoutException) {
            return $this->error('A refund request is already in progress. Please try again.', 409, ['code' => 'refund_in_progress']);
        } catch (\Throwable $e) {
            Log::error('Stripe refund failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            return $this->stripeFailure($order, $payments, $e);
        }

        if ($result === 'pending') {
            return $this->success(['order_id' => $order->id, 'refund_status' => 'pending'], 'Refund is pending confirmation from Stripe.', 202);
        }

        return $this->success(
            ['message' => "Order #{$order->order_number} has been refunded successfully."],
            'Order refunded.'
        );
    }

    private function settleCancelledOrder(Order $order, OrderPaymentService $payments, $actor, ?string $reason): JsonResponse
    {
        if (in_array($order->refund_status, ['released', 'succeeded'], true)) {
            return $this->error('The payment for this cancelled order has already been returned.', 409);
        }

        if ($order->refund_status === 'none') {
            // e.g. an order cancelled before automatic settlement existed.
            $order->update(['refund_status' => 'pending']);
        }

        try {
            $refundStatus = $payments->settleCancellation(
                $order,
                $order->refund_status === 'failed' ? 'admin_retry' : 'admin_cancel',
                $actor,
                $reason,
            );
        } catch (RefundNotAllowed $e) {
            return $this->error($e->getMessage(), $e->httpStatus, $e->toErrors());
        } catch (LockTimeoutException) {
            return $this->error('A refund request is already in progress. Please try again.', 409, ['code' => 'refund_in_progress']);
        } catch (\Throwable $e) {
            Log::error('Returning payment for a cancelled order failed.', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return $this->stripeFailure($order, $payments, $e);
        }

        $data = ['order_id' => $order->id, 'refund_status' => $refundStatus];

        return $refundStatus === 'pending'
            ? $this->success($data, 'Refund is pending confirmation from Stripe.', 202)
            : $this->success($data, $refundStatus === 'released' ? 'Card hold released.' : 'Order refunded.');
    }

    private function stripeFailure(Order $order, OrderPaymentService $payments, \Throwable $e): JsonResponse
    {
        $failure = $payments->recordRefundFailure($order, $e);
        $advice = RefundAdvice::forFailure($failure['code'], $failure['message'], (int) $order->fresh()->refund_failures);

        return $this->error('Stripe refund failed.', 502, [
            'stripe_code' => $failure['code'],
            'stripe_message' => $failure['message'],
            'next_action' => $advice['next_action'],
        ]);
    }

    private function expirePendingPaymentSession(Order $order, StripeCheckoutService $stripe): bool
    {
        if (! $order->stripe_session_id) {
            return true;
        }

        $session = $stripe->retrieveCheckoutSession($order->stripe_session_id);

        if ($session->status === 'complete') {
            return false;
        }

        if ($session->status === 'open') {
            $stripe->expireCheckoutSession($order->stripe_session_id);
        }

        return true;
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

    private function error(string $message, int $status, $errors = null)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }
}
