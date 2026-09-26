<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\EasyPostServiceInterface;
use App\Exceptions\CouponValidationException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ShippingProviderException;
use App\Exceptions\ShippingValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCancellationRequestRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Jobs\SendAdminAlert;
use App\Mail\OrderPaidMail;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Payment;
use App\Models\ShippingRateQuote;
use App\Services\CouponService;
use App\Services\FreeShippingService;
use App\Services\OrderInventoryService;
use App\Services\ShipmentTrackingService;
use App\Services\ShippingQuoteService;
use App\Services\StripeCheckoutService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
    #[OA\Get(
        path: '/api/v1/orders',
        summary: 'List Orders',
        description: "Get a paginated list of the authenticated user's orders",
        security: [['bearerAuth' => []]],
        tags: ['Orders']
    )]
    #[OA\Response(
        response: 200,
        description: 'Successful response',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/Order')
                ),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/UnauthorizedResponse')]
    public function index(Request $request)
    {
        $orders = $request->user()->orders()
            ->with(['items.product.media', 'items.variant', 'cancellationRequest'])
            ->latest()
            ->paginate(10);

        return OrderResource::collection($orders);
    }

    #[OA\Get(
        path: '/api/v1/orders/{id}',
        summary: 'Get Order Details',
        description: 'Get details of a specific order belonging to the user',
        security: [['bearerAuth' => []]],
        tags: ['Orders']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Successful response',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Order'),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/UnauthorizedResponse')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function show(Request $request, $id)
    {
        $order = $request->user()->orders()
            ->with(['items.product', 'items.variant', 'payment', 'cancellationRequest'])
            ->findOrFail($id);

        return new OrderResource($order);
    }

    #[OA\Post(
        path: '/api/v1/orders/{id}/checkout-session',
        summary: 'Resume payment',
        description: 'Return the still-open Stripe Checkout Session for an unpaid order, or create a replacement after Stripe confirms that the previous session expired.',
        security: [['bearerAuth' => []]],
        tags: ['Orders']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Checkout session is ready.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Existing checkout session retrieved.'),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'checkout_url', type: 'string', example: 'https://checkout.stripe.com/c/pay/cs_test_a1b2c3d4'),
                        new OA\Property(
                            property: 'payment',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'session_id', type: 'string', example: 'cs_test_a1b2c3d4'),
                                new OA\Property(property: 'reused', type: 'boolean', example: true),
                            ]
                        ),
                    ]
                ),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/UnauthorizedResponse')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    #[OA\Response(response: 409, description: 'The order cannot currently be paid or another resume request is in progress.')]
    #[OA\Response(response: 502, description: 'Payment provider error.')]
    public function resumeCheckout(Request $request, $id, StripeCheckoutService $stripe): JsonResponse
    {
        // Resolve through the relationship so another customer's order is never disclosed.
        $order = $request->user()->orders()->findOrFail($id);

        try {
            return Cache::lock("order:{$order->id}:checkout-session", 60)->block(5, function () use ($order, $stripe): JsonResponse {
                $order->refresh();

                if ($order->status !== 'pending_payment' || $order->payment_status !== 'unpaid') {
                    return $this->checkoutConflictResponse();
                }

                if ($order->stripe_session_id) {
                    $existingSession = $stripe->retrieveCheckoutSession($order->stripe_session_id);

                    if ($existingSession->status === 'open' && $existingSession->url) {
                        return $this->checkoutSessionResponse($existingSession, reused: true);
                    }

                    if ($existingSession->status === 'complete') {
                        return response()->json([
                            'success' => false,
                            'message' => 'Payment has already completed and is awaiting confirmation.',
                            'data' => null,
                            'errors' => null,
                        ], 409);
                    }

                    if ($existingSession->status !== 'expired') {
                        throw new \RuntimeException('Stripe returned an unsupported checkout session status.');
                    }
                }

                $order->loadMissing('items');
                $session = $stripe->createCheckoutSession($order);

                $updated = Order::query()
                    ->whereKey($order->id)
                    ->where('status', 'pending_payment')
                    ->where('payment_status', 'unpaid')
                    ->where('stripe_session_id', $order->stripe_session_id)
                    ->update(['stripe_session_id' => $session->id]);

                if ($updated === 0) {
                    try {
                        $stripe->expireCheckoutSession($session->id);
                    } catch (\Throwable $e) {
                        Log::critical('Unable to expire a checkout session created during an order state race.', [
                            'order_id' => $order->id,
                            'stripe_session_id' => $session->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    return $this->checkoutConflictResponse();
                }

                return $this->checkoutSessionResponse($session, reused: false);
            });
        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'message' => 'A checkout session request is already in progress. Please try again.',
                'data' => null,
                'errors' => null,
            ], 409);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout session resume failed.', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment provider error. Please try again.',
                'data' => null,
                'errors' => null,
            ], 502);
        }
    }

    private function checkoutSessionResponse(object $session, bool $reused): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $reused ? 'Existing checkout session retrieved.' : 'Checkout session created.',
            'data' => [
                'checkout_url' => $session->url,
                'payment' => [
                    'session_id' => $session->id,
                    'reused' => $reused,
                ],
            ],
            'errors' => null,
        ]);
    }

    private function checkoutConflictResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Only unpaid orders awaiting payment can start or resume checkout.',
            'data' => null,
            'errors' => null,
        ], 409);
    }

    #[OA\Post(
        path: '/api/v1/orders',
        summary: 'Create Order',
        description: 'Create a new order for the authenticated user and generate a Stripe Checkout Session.',
        security: [['bearerAuth' => []]],
        tags: ['Orders']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['items', 'shipping_address', 'shipping_rate_id'],
            properties: [
                new OA\Property(
                    property: 'items',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'product_id', type: 'integer', example: 1),
                            new OA\Property(property: 'variant_id', type: 'integer', nullable: true, example: null),
                            new OA\Property(property: 'quantity', type: 'integer', example: 2),
                        ]
                    )
                ),
                new OA\Property(
                    property: 'shipping_address',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                        new OA\Property(property: 'line1', type: 'string', example: '123 Main St'),
                        new OA\Property(property: 'city', type: 'string', example: 'New York'),
                        new OA\Property(property: 'state', type: 'string', example: 'NY'),
                        new OA\Property(property: 'postal_code', type: 'string', example: '10001'),
                        new OA\Property(property: 'country', type: 'string', example: 'US'),
                        new OA\Property(property: 'phone', type: 'string', example: '+1234567890'),
                    ]
                ),
                new OA\Property(
                    property: 'billing_address',
                    type: 'object',
                    nullable: true,
                    properties: [
                        new OA\Property(property: 'first_name', type: 'string', example: 'John'),
                        new OA\Property(property: 'last_name', type: 'string', example: 'Doe'),
                        new OA\Property(property: 'address_line_1', type: 'string', example: '123 Main St'),
                        new OA\Property(property: 'city', type: 'string', example: 'New York'),
                        new OA\Property(property: 'state', type: 'string', example: 'NY'),
                        new OA\Property(property: 'postal_code', type: 'string', example: '10001'),
                        new OA\Property(property: 'country', type: 'string', example: 'US'),
                        new OA\Property(property: 'phone', type: 'string', example: '+1234567890'),
                    ]
                ),
                new OA\Property(
                    property: 'coupon_code',
                    type: 'string',
                    nullable: true,
                    example: 'SAVE50'
                ),
                new OA\Property(property: 'shipping_rate_id', type: 'string', example: 'rate_abc123'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Order created successfully. Redirect to Stripe checkout.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Order created. Redirect to Stripe checkout.'),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'order', ref: '#/components/schemas/Order'),
                        new OA\Property(property: 'checkout_url', type: 'string', example: 'https://checkout.stripe.com/c/pay/cs_test_a1b2c3d4'),
                        new OA\Property(
                            property: 'payment',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'session_id', type: 'string', example: 'cs_test_a1b2c3d4'),
                            ]
                        ),
                    ]
                ),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/UnauthorizedResponse')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    #[OA\Response(
        response: 502,
        description: 'Payment provider error.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Payment provider error. Please try again.'),
                new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
            ]
        )
    )]
    public function store(StoreOrderRequest $request, StripeCheckoutService $stripe, CouponService $couponService, OrderInventoryService $inventory, ShippingQuoteService $quoteService, FreeShippingService $freeShipping): JsonResponse
    {
        if (! $request->user()->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Email verification is required before checkout.',
                'data' => null,
                'errors' => ['email' => ['Verify your email before checkout.']],
            ], 403);
        }

        $items = $request->validated('items');

        try {
            $shippingQuote = $quoteService->validateForCheckout(
                $request->validated('shipping_rate_id'),
                $request->validated('shipping_address'),
                $items
            );
        } catch (ShippingValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => ['shipping_rate_id' => [$e->getMessage()], 'code' => $e->errorCode],
            ], 422);
        } catch (ShippingProviderException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => ['shipping_rate_id' => ['Shipping provider verification failed.']],
            ], 503);
        }

        // 1️⃣ Create order in DB (pending_payment, unpaid)
        try {
            $order = DB::transaction(function () use ($request, $items, $couponService, $inventory, $shippingQuote, $quoteService, $freeShipping) {
                $lockedQuote = $quoteService->lockAvailableQuote($shippingQuote->id);

                // subtotal
                $quote = $inventory->quoteAndReserve($items);
                $subtotal = $quote['subtotal'];
                $orderItemsData = $quote['items'];

                // discounts
                $coupon = null;
                $discount = 0.0;
                if ($request->filled('coupon_code')) {
                    // lockForUpdate is executed within validateCoupon if in transaction
                    $coupon = $couponService->validateCoupon($request->coupon_code, $request->user(), $subtotal);
                    $discount = $couponService->calculateDiscount($coupon, $subtotal);
                }

                // free-shipping eligibility (automatic threshold OR a free_shipping coupon)
                $carrierShippingCost = (float) $lockedQuote->amount;
                $freeShippingReason = null;
                if ($freeShipping->subtotalQualifies(max(0.0, $subtotal - $discount))) {
                    $freeShippingReason = 'threshold';
                } elseif ($coupon && $coupon->isFreeShipping()) {
                    $freeShippingReason = 'coupon';
                }

                // shipping (the real carrier cost is preserved separately for accounting)
                $shippingCost = $freeShippingReason ? 0.0 : $carrierShippingCost;

                // tax — not yet calculated (pending product decision); kept as an explicit
                // term so the formula matches subtotal - discount + shipping + tax.
                $tax = 0.0;

                // final total
                $total = max(0.0, $subtotal - $discount + $shippingCost + $tax);

                if ($total > 0 && $total < (float) config('services.stripe.minimum_charge', 0.50)) {
                    throw new ShippingValidationException('The order total is below the minimum charge amount.', 'minimum_charge');
                }

                $order = Order::create([
                    'order_number' => 'ORD-'.strtoupper(Str::random(10)),
                    'user_id' => $request->user()->id,
                    'status' => 'pending_payment',
                    'payment_status' => 'unpaid',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'shipping_cost' => $shippingCost,
                    'carrier_shipping_cost' => $carrierShippingCost,
                    'free_shipping_reason' => $freeShippingReason,
                    'easypost_shipment_id' => $lockedQuote->shipment_id,
                    'shipping_rate_id' => $lockedQuote->rate_id,
                    'shipping_carrier' => $lockedQuote->carrier,
                    'shipping_service' => $lockedQuote->service,
                    'total' => $total,
                    'shipping_address' => $request->shipping_address,
                    'billing_address' => $request->billing_address ?? $request->shipping_address,
                    'stock_reserved_at' => now(),
                ]);

                if ($coupon) {
                    $couponService->applyCouponToOrder($coupon, $order, $discount);
                    $order->save();

                    // Create usage record
                    CouponUsage::create([
                        'coupon_id' => $coupon->id,
                        'user_id' => $request->user()->id,
                        'order_id' => $order->id,
                        'discount_amount' => $discount,
                    ]);

                    // Safe atomic increment
                    $coupon->increment('used_count');
                }

                foreach ($orderItemsData as $data) {
                    $order->items()->create($data);
                }

                $lockedQuote->update(['consumed_at' => now(), 'order_id' => $order->id]);

                return $order->load('items');
            }, 3);
        } catch (CouponValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => [
                    'coupon_code' => [$e->getMessage()],
                ],
            ], 422);
        } catch (InsufficientStockException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => [$e->field => [$e->getMessage()]],
            ], 409);
        } catch (ShippingValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => ['shipping_rate_id' => [$e->getMessage()], 'code' => $e->errorCode],
            ], 422);
        }

        // 2️⃣ Create Stripe Checkout Session
        if ((float) $order->total === 0.0) {
            DB::transaction(function () use ($order): void {
                $order->update(['status' => 'processing', 'payment_status' => 'paid', 'paid_at' => now()]);
                Payment::create(['order_id' => $order->id, 'payment_provider' => 'free', 'status' => 'completed', 'amount' => 0]);
            });

            SendAdminAlert::dispatch("New free order {$order->order_number}")->onQueue('notifications');
            Mail::to($order->user()->value('email'))->queue(new OrderPaidMail($order));

            return response()->json([
                'success' => true,
                'message' => 'Order created without payment.',
                'data' => ['order' => new OrderResource($order), 'checkout_url' => null, 'payment_required' => false, 'payment' => ['session_id' => null]],
                'errors' => null,
            ], 201);
        }

        try {
            $session = $stripe->createCheckoutSession($order);

            $order->update([
                'stripe_session_id' => $session->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout session creation failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->rollbackFailedCheckout($order);

            return response()->json([
                'success' => false,
                'message' => 'Payment provider error. Please try again.',
                'data' => null,
                'errors' => null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order created. Redirect to Stripe checkout.',
            'data' => [
                'order' => new OrderResource($order),
                'checkout_url' => $session->url,
                'payment' => [
                    'session_id' => $session->id,
                ],
            ],
            'errors' => null,
        ], 201);
    }

    private function rollbackFailedCheckout(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $usage = CouponUsage::query()
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->first();

            if ($usage) {
                $coupon = Coupon::query()->whereKey($usage->coupon_id)->lockForUpdate()->first();
                if ($coupon && $coupon->used_count > 0) {
                    $coupon->decrement('used_count');
                }
                $usage->delete();
            }

            ShippingRateQuote::query()
                ->where('order_id', $lockedOrder->id)
                ->update(['consumed_at' => null, 'order_id' => null]);

            // Updating the status invokes OrderObserver, which idempotently
            // releases the reserved inventory before the order is hidden.
            $lockedOrder->update(['status' => 'cancelled', 'payment_status' => 'failed']);
            $lockedOrder->delete();
        }, 3);
    }

    #[OA\Post(
        path: '/api/v1/orders/{id}/cancel',
        summary: 'Cancel Order (Direct)',
        description: 'Immediately cancel a **pending** order. Only allowed within **3 hours** of creation.',
        security: [['bearerAuth' => []]],
        tags: ['Orders']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Order cancelled successfully.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Order cancelled successfully.'),
                new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Cannot cancel order (wrong status or window expired).',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Only pending orders can be cancelled directly.'),
                new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/UnauthorizedResponse')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function cancel(Request $request, $id): JsonResponse
    {
        $order = $request->user()->orders()->findOrFail($id);

        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending orders can be cancelled directly.',
                'data' => null,
                'errors' => null,
            ], 400);
        }

        // Enforce 3-hour direct-cancel window
        if ($order->created_at->diffInHours(now()) >= 3) {
            return response()->json([
                'success' => false,
                'message' => 'The 3-hour direct cancellation window has passed. Please submit a cancellation request instead.',
                'data' => null,
                'errors' => null,
            ], 400);
        }

        $order->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully.',
            'data' => null,
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/orders/{id}/cancellation-request',
        summary: 'Submit Cancellation Request',
        description: 'Submit a cancellation request for an order. Cannot be used if order is shipped, delivered, or cancelled, or if a pending request already exists.',
        security: [['bearerAuth' => []]],
        tags: ['Orders']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['reason'],
            properties: [
                new OA\Property(property: 'reason', type: 'string', minLength: 10, example: 'I ordered by mistake and need to cancel.'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Cancellation request submitted successfully.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Your cancellation request has been submitted and is under review.'),
                new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/UnauthorizedResponse')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function requestCancellation(StoreCancellationRequestRequest $request, $id): JsonResponse
    {
        $order = $request->user()->orders()->findOrFail($id);

        // Block if order is in a terminal/non-cancellable state
        $blockedStatuses = ['shipped', 'delivered', 'cancelled'];
        if (in_array($order->status, $blockedStatuses, true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot request cancellation for an order with status '{$order->status}'.",
                'data' => null,
                'errors' => null,
            ], 422);
        }

        // Reject if a pending request already exists
        $exists = OrderCancellationRequest::where('order_id', $order->id)
            ->where('status', 'pending')
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'A cancellation request is already pending for this order.',
                'data' => null,
                'errors' => null,
            ], 422);
        }

        OrderCancellationRequest::create([
            'order_id' => $order->id,
            'user_id' => $request->user()->id,
            'reason' => $request->validated('reason'),
            'status' => 'pending',
        ]);

        $message = "⚠️ Cancellation request for order {$order->order_number} — Reason: \"".$request->validated('reason').'"';
        SendAdminAlert::dispatch($message)->onQueue('notifications');

        return response()->json([
            'success' => true,
            'message' => 'Your cancellation request has been submitted and is under review.',
            'data' => null,
            'errors' => null,
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/orders/{id}/tracking',
        summary: 'Get Customer Order Tracking Info',
        description: 'Get real-time tracking details from EasyPost for the authenticated user\'s shipped order.',
        security: [['bearerAuth' => []]],
        tags: ['Orders']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
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
                        new OA\Property(property: 'status_detail', type: 'string', nullable: true, example: 'en_route'),
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
    #[OA\Response(response: 422, description: 'Tracking info not available yet.')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function getTracking(Request $request, int $id, EasyPostServiceInterface $easyPostService, ShipmentTrackingService $trackingService): JsonResponse
    {
        $order = $request->user()->orders()->findOrFail($id);

        if (! $order->tracking_number) {
            return response()->json([
                'success' => false,
                'message' => 'Order has not been shipped yet or does not have a tracking number.',
                'data' => null,
                'errors' => [
                    'tracking' => ['No tracking number associated with this order.'],
                ],
            ], 422);
        }

        try {
            $shipment = $easyPostService->retrieveShipment($order->easypost_shipment_id);
            $tracker = $shipment->tracker;

            if (! $tracker) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tracking details available yet for this shipment.',
                    'data' => null,
                    'errors' => [
                        'tracking' => ['Tracker has not been generated by carrier yet.'],
                    ],
                ], 422);
            }

            $order = $trackingService->sync($order, $tracker);

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

            return response()->json([
                'success' => true,
                'message' => 'Tracking info retrieved.',
                'data' => [
                    'tracking_code' => $tracker->tracking_code,
                    'status' => $tracker->status,
                    'status_detail' => $tracker->status_detail ?? null,
                    'est_delivery_date' => $tracker->est_delivery_date ?? null,
                    'tracking_details' => $trackingDetails,
                    'events' => $order->tracking_events ?? [],
                ],
                'errors' => null,
            ]);

        } catch (\Exception $e) {
            Log::error('Customer Shipping getTracking failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve tracking info.',
                'data' => null,
                'errors' => [
                    'tracking' => ['Tracking provider request failed.'],
                ],
            ], 422);
        }
    }
}
