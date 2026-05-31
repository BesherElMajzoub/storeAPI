<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCancellationRequestRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Jobs\SendAdminAlert;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Product;
use App\Services\StripeCheckoutService;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        path: '/api/v1/orders',
        summary: 'Create Order',
        description: 'Create a new order for the authenticated user and generate a Stripe Checkout Session.',
        security: [['bearerAuth' => []]],
        tags: ['Orders']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['items', 'shipping_address'],
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
                        new OA\Property(property: 'first_name', type: 'string', example: 'John'),
                        new OA\Property(property: 'last_name', type: 'string', example: 'Doe'),
                        new OA\Property(property: 'address_line_1', type: 'string', example: '123 Main St'),
                        new OA\Property(property: 'city', type: 'string', example: 'New York'),
                        new OA\Property(property: 'state', type: 'string', example: 'NY'),
                        new OA\Property(property: 'postal_code', type: 'string', example: '10001'),
                        new OA\Property(property: 'country', type: 'string', example: 'US'),
                        new OA\Property(property: 'phone', type: 'string', example: '+1234567890')
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
                        new OA\Property(property: 'phone', type: 'string', example: '+1234567890')
                    ]
                ),
                new OA\Property(
                    property: 'coupon_code',
                    type: 'string',
                    nullable: true,
                    example: 'SAVE50'
                )
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
                                new OA\Property(property: 'session_id', type: 'string', example: 'cs_test_a1b2c3d4')
                            ]
                        )
                    ]
                ),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
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
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
            ]
        )
    )]
    public function store(StoreOrderRequest $request, StripeCheckoutService $stripe, CouponService $couponService): JsonResponse
    {
        $items = $request->items;
        $subtotal = 0.0;
        $orderItemsData = [];

        foreach ($items as $item) {
            $product = Product::findOrFail($item['product_id']);
            $price = (float) $product->final_price;

            $variant = isset($item['variant_id']) && $item['variant_id']
                ? \App\Models\ProductVariant::find($item['variant_id'])
                : null;

            if ($variant && $variant->price !== null) {
                $price = (float) $variant->price;
            }

            $lineTotal = $price * $item['quantity'];
            $subtotal += $lineTotal;

            $orderItemsData[] = [
                'product_id'         => $product->id,
                'product_name'       => $product->name,
                'variant_id'         => $variant?->id,
                'variant_name'       => $variant?->name,
                'variant_attributes' => $variant?->attributes,   // JSON snapshot
                'sku'                => $variant?->sku ?? $product->sku,
                'price'              => $price,
                'quantity'           => $item['quantity'],
                'total'              => $lineTotal,
            ];
        }

        $shippingCost = 0.0;
        $easypostShipmentId = null;

        if ($request->filled('shipping_rate_id') && $request->filled('easypost_shipment_id')) {
            try {
                $client = new \EasyPost\EasyPostClient(config('services.easypost.api_key'));
                $shipment = $client->shipment->retrieve($request->easypost_shipment_id);
                
                // Find matching rate
                $matchingRate = collect($shipment->rates)->first(fn($rate) => $rate->id === $request->shipping_rate_id);
                
                if ($matchingRate) {
                    $shippingCost = (float) $matchingRate->rate;
                    $easypostShipmentId = $shipment->id;
                } else {
                    throw new \Exception("Selected shipping rate is not valid for this shipment.");
                }
            } catch (\Throwable $e) {
                Log::error('EasyPost rate verification failed during checkout', [
                    'shipment_id' => $request->easypost_shipment_id,
                    'rate_id' => $request->shipping_rate_id,
                    'error' => $e->getMessage(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid shipping rate selected: ' . $e->getMessage(),
                    'data'    => null,
                    'errors'  => [
                        'shipping_rate_id' => ['Invalid shipping rate selected.']
                    ],
                ], 422);
            }
        }

        // 1️⃣ Create order in DB (pending_payment, unpaid)
        try {
            $order = DB::transaction(function () use ($request, $subtotal, $orderItemsData, $couponService, $shippingCost, $easypostShipmentId) {
                $coupon = null;
                $discount = 0.0;

                if ($request->filled('coupon_code')) {
                    // lockForUpdate is executed within validateCoupon if in transaction
                    $coupon = $couponService->validateCoupon($request->coupon_code, $request->user(), $subtotal);
                    $discount = $couponService->calculateDiscount($coupon, $subtotal);
                }

                $total = max(0.0, $subtotal + $shippingCost - $discount);

                $order = Order::create([
                    'order_number'         => 'ORD-'.strtoupper(Str::random(10)),
                    'user_id'              => $request->user()->id,
                    'status'               => 'pending_payment',
                    'payment_status'       => 'unpaid',
                    'subtotal'             => $subtotal,
                    'shipping_cost'        => $shippingCost,
                    'easypost_shipment_id' => $easypostShipmentId,
                    'total'                => $total,
                    'shipping_address'     => $request->shipping_address,
                    'billing_address'      => $request->billing_address ?? $request->shipping_address,
                ]);

                if ($coupon) {
                    $couponService->applyCouponToOrder($coupon, $order, $discount);
                    $order->save();

                    // Create usage record
                    \App\Models\CouponUsage::create([
                        'coupon_id'       => $coupon->id,
                        'user_id'         => $request->user()->id,
                        'order_id'        => $order->id,
                        'discount_amount' => $discount,
                    ]);

                    // Safe atomic increment
                    $coupon->increment('used_count');
                }

                foreach ($orderItemsData as $data) {
                    $order->items()->create($data);
                }

                return $order->load('items');
            });
        } catch (\App\Exceptions\CouponValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => null,
                'errors'  => [
                    'coupon_code' => [$e->getMessage()]
                ],
            ], 422);
        }

        // 2️⃣ Create Stripe Checkout Session
        try {
            $session = $stripe->createCheckoutSession($order);

            $order->update([
                'stripe_session_id' => $session->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout session creation failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            // Roll back the order so the user can try again
            $order->delete();

            return response()->json([
                'success' => false,
                'message' => 'Payment provider error. Please try again.',
                'data'    => null,
                'errors'  => null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order created. Redirect to Stripe checkout.',
            'data'    => [
                'order'        => new OrderResource($order),
                'checkout_url' => $session->url,
                'payment'      => [
                    'session_id' => $session->id,
                ],
            ],
            'errors'  => null,
        ], 201);
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
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
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
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
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
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
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
}
