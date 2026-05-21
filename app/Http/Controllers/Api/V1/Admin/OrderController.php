<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Services\StripeCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
    #[OA\Get(
        path: "/api/v1/admin/orders",
        summary: "Admin List Orders",
        description: "List all orders with filtering",
        security: [["bearerAuth" => []]],
        tags: ["Admin Orders"]
    )]
    #[OA\Parameter(name: "per_page", in: "query", schema: new OA\Schema(type: "integer", default: 20))]
    #[OA\Parameter(name: "status", in: "query", schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "payment_status", in: "query", schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "user_id", in: "query", schema: new OA\Schema(type: "integer"))]
    #[OA\Parameter(name: "search", in: "query", schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "date_from", in: "query", schema: new OA\Schema(type: "string", format: "date"))]
    #[OA\Parameter(name: "date_to", in: "query", schema: new OA\Schema(type: "string", format: "date"))]
    #[OA\Response(
        response: 200,
        description: "Orders fetched",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(
                    property: "data",
                    type: "object",
                    properties: [
                        new OA\Property(
                            property: "data",
                            type: "array",
                            items: new OA\Items(ref: "#/components/schemas/Order")
                        )
                    ]
                )
            ]
        )
    )]
    #[OA\Response(response: 401, ref: "#/components/responses/ErrorResponse")]
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $orders = Order::query()
            ->with('user')
            ->when($request->filled('status'), fn($q) => $q->status($request->input('status')))
            ->when($request->filled('payment_status'), fn($q) => $q->paymentStatus($request->input('payment_status')))
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', (int) $request->input('user_id')))
            ->when($request->filled('search'), fn($q) => $q->search($request->input('search')))
            ->dateRange($request->input('date_from'), $request->input('date_to'))
            ->latest()
            ->paginate($perPage);

        return $this->success($orders, 'Orders fetched.');
    }

    #[OA\Get(
        path: "/api/v1/admin/orders/{id}",
        summary: "Admin Show Order",
        description: "Show details of a specific order",
        security: [["bearerAuth" => []]],
        tags: ["Admin Orders"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Order fetched",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", ref: "#/components/schemas/Order")
            ]
        )
    )]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function show($id)
    {
        $order = Order::with(['items', 'user', 'payment'])->findOrFail($id);

        return $this->success($order, 'Order fetched.');
    }

    #[OA\Post(
        path: "/api/v1/admin/orders/{id}/status",
        summary: "Admin Update Order Status",
        description: "Transition order status or payment status",
        security: [["bearerAuth" => []]],
        tags: ["Admin Orders"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "status", type: "string", description: "pending, processing, shipped, delivered, cancelled", nullable: true),
                new OA\Property(property: "payment_status", type: "string", description: "unpaid, paid, failed, refunded", nullable: true)
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: "Order status updated",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", ref: "#/components/schemas/Order")
            ]
        )
    )]
    #[OA\Response(response: 409, description: "Status transition not allowed")]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function updateStatus(UpdateOrderStatusRequest $request, $id)
    {
        $order = Order::findOrFail($id);
        $data = $request->validated();

        $errors = [];
        if (array_key_exists('status', $data)) {
            if (!$this->canTransition($order->status, $data['status'], $this->statusTransitions())) {
                $errors['status'] = ['Invalid status transition.'];
            }
        }

        if (array_key_exists('payment_status', $data)) {
            if (!$this->canTransition($order->payment_status, $data['payment_status'], $this->paymentStatusTransitions())) {
                $errors['payment_status'] = ['Invalid payment status transition.'];
            }
        }

        if ($errors) {
            return $this->error('Status transition not allowed.', 409, $errors);
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
            'pending'         => ['processing', 'cancelled'],
            'pending_payment' => ['cancelled'],
            'processing'      => ['shipped', 'cancelled'],
            'shipped'         => ['delivered', 'refunded'],
            'delivered'       => ['refunded'],
            'cancelled'       => [],
            'refunded'        => [],
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
        path: "/api/v1/admin/orders/{order}/refund",
        summary: "Admin Refund Order",
        description: "Issue a full Stripe refund for a paid order and mark it as refunded.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Orders"]
    )]
    #[OA\Parameter(name: "order", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "Order refunded")]
    #[OA\Response(response: 409, description: "Order not eligible for refund")]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function refund(int $order, StripeCheckoutService $stripe): JsonResponse
    {
        $order = Order::findOrFail($order);

        if (!$order->isPaid()) {
            return $this->error('Order is not paid and cannot be refunded.', 409);
        }

        if ($order->isRefunded()) {
            return $this->error('Order has already been refunded.', 409);
        }

        if (!$order->stripe_payment_intent_id) {
            return $this->error('No Stripe PaymentIntent found for this order.', 409);
        }

        try {
            $stripe->refundOrder($order);
        } catch (\Throwable $e) {
            Log::error('Stripe refund failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            return $this->error('Stripe refund failed: ' . $e->getMessage(), 502);
        }

        $order->update([
            'status'         => 'refunded',
            'payment_status' => 'refunded',
            'refunded_at'    => now(),
        ]);

        return $this->success(
            ['message' => "Order #{$order->order_number} has been refunded successfully."],
            'Order refunded.'
        );
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
