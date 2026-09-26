<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BulkUpdateOrderStatusRequest;
use App\Http\Requests\Api\V1\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\AdminOrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\StripeCheckoutService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            ->with(['user', 'items.product.media', 'items.variant', 'payment'])
            ->when($request->filled('status'), fn ($q) => $q->status($request->input('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->paymentStatus($request->input('payment_status')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', (int) $request->input('user_id')))
            ->when($request->filled('search'), fn ($q) => $q->search($request->input('search')))
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
        $order = Order::with(['items', 'user', 'payment'])->findOrFail($id);

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
    #[OA\Response(response: 409, description: 'At least one transition is invalid; no orders changed')]
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
                }
            }

            if ($errors !== []) {
                return ['errors' => $errors, 'orders' => collect()];
            }

            foreach ($orders as $order) {
                $order->update(['status' => $validated['status']]);
            }

            return [
                'errors' => [],
                'orders' => Order::query()
                    ->whereKey($validated['ids'])
                    ->with(['items.product.media', 'items.variant', 'user', 'payment'])
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
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Order status updated',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Order'),
            ]
        )
    )]
    #[OA\Response(response: 409, description: 'Status transition not allowed')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function updateStatus(UpdateOrderStatusRequest $request, $id, StripeCheckoutService $stripe)
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
            $order->update($data);

            return $order->refresh()->load(['items', 'user', 'payment']);
        });

        return $this->success($order, 'Order status updated.');
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
        description: 'Issue a full Stripe refund for a paid order and mark it as refunded.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Orders']
    )]
    #[OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Order refunded')]
    #[OA\Response(response: 202, description: 'Refund accepted and awaiting Stripe confirmation')]
    #[OA\Response(response: 409, description: 'Order not eligible for refund')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function refund(int $order, StripeCheckoutService $stripe): JsonResponse
    {
        $order = Order::findOrFail($order);

        try {
            return Cache::lock("order:{$order->id}:refund", 60)->block(5, function () use ($order, $stripe): JsonResponse {
                $order->refresh();
                $requiresRefund = Payment::query()
                    ->where('order_id', $order->id)
                    ->value('status') === 'requires_refund';

                if (! $order->isPaid() && ! $requiresRefund) {
                    return $this->error('Order is not paid and cannot be refunded.', 409);
                }

                if ($order->isRefunded()) {
                    return $this->error('Order has already been refunded.', 409);
                }

                if (! $order->stripe_payment_intent_id) {
                    return $this->error('No Stripe PaymentIntent found for this order.', 409);
                }

                try {
                    $refund = $stripe->refundOrder($order);

                    if ($refund->status !== 'succeeded') {
                        if (! in_array($refund->status, ['failed', 'canceled'], true)) {
                            Log::warning('Stripe refund is pending confirmation.', [
                                'order_id' => $order->id,
                                'refund_status' => $refund->status,
                            ]);

                            return $this->success([
                                'order_id' => $order->id,
                                'refund_status' => $refund->status,
                            ], 'Refund is pending confirmation from Stripe.', 202);
                        }

                        Log::warning('Stripe refund is pending confirmation.', [
                            'order_id' => $order->id,
                            'refund_status' => $refund->status,
                        ]);

                        return $this->error('Stripe refund failed.', 502);
                    }
                } catch (\Throwable $e) {
                    Log::error('Stripe refund failed', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);

                    return $this->error('Stripe refund failed.', 502);
                }

                $order->update([
                    'status' => 'refunded',
                    'payment_status' => 'refunded',
                    'refunded_amount' => $order->total,
                    'refunded_at' => now(),
                ]);
                $order->payment()?->update([
                    'status' => 'refunded',
                    'amount' => $order->total,
                ]);

                return $this->success(
                    ['message' => "Order #{$order->order_number} has been refunded successfully."],
                    'Order refunded.'
                );
            });
        } catch (LockTimeoutException) {
            return $this->error('A refund request is already in progress. Please try again.', 409);
        }
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
