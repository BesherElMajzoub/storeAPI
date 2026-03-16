<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
    #[OA\Get(
        path: "/api/v1/orders",
        summary: "List Orders",
        description: "Get a paginated list of the authenticated user's orders",
        security: [["bearerAuth" => []]],
        tags: ["Orders"]
    )]
    #[OA\Response(
        response: 200,
        description: "Successful response",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(
                    property: "data",
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/Order")
                ),
                new OA\Property(property: "meta", ref: "#/components/schemas/PaginationMeta")
            ]
        )
    )]
    #[OA\Response(response: 401, ref: "#/components/responses/ErrorResponse")]
    public function index(Request $request)
    {
        $orders = $request->user()->orders()
            ->latest()
            ->paginate(10);

        return OrderResource::collection($orders);
    }

    #[OA\Get(
        path: "/api/v1/orders/{id}",
        summary: "Get Order Details",
        description: "Get details of a specific order belonging to the user",
        security: [["bearerAuth" => []]],
        tags: ["Orders"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Successful response",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", ref: "#/components/schemas/Order")
            ]
        )
    )]
    #[OA\Response(response: 401, ref: "#/components/responses/ErrorResponse")]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function show(Request $request, $id)
    {
        $order = $request->user()->orders()
            ->with(['items.product', 'payment'])
            ->findOrFail($id);

        return new OrderResource($order);
    }

    #[OA\Post(
        path: "/api/v1/orders",
        summary: "Create Order",
        description: "Create a new order for the authenticated user",
        security: [["bearerAuth" => []]],
        tags: ["Orders"]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["items", "shipping_address"],
            properties: [
                new OA\Property(
                    property: "items",
                    type: "array",
                    items: new OA\Items(
                        type: "object",
                        properties: [
                            new OA\Property(property: "product_id", type: "integer", example: 1),
                            new OA\Property(property: "quantity", type: "integer", example: 2)
                        ]
                    )
                ),
                new OA\Property(property: "shipping_address", type: "object", description: "Shipping address details"),
                new OA\Property(property: "billing_address", type: "object", description: "Billing address details (optional)")
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: "Order created successfully",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", ref: "#/components/schemas/Order")
            ]
        )
    )]
    #[OA\Response(response: 422, ref: "#/components/responses/ValidationErrorResponse")]
    #[OA\Response(response: 401, ref: "#/components/responses/ErrorResponse")]
    public function store(StoreOrderRequest $request)
    {
        $items = $request->items;
        $subtotal = 0;
        $orderItemsData = [];

        foreach ($items as $item) {
            $product = Product::findOrFail($item['product_id']);
            $price = $product->final_price;
            $startTotal = $price * $item['quantity'];
            $subtotal += $startTotal;

            $orderItemsData[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'price' => $price,
                'quantity' => $item['quantity'],
                'total' => $startTotal,
            ];
        }

        $total = $subtotal; // + tax + shipping - discount

        $order = DB::transaction(function () use ($request, $subtotal, $total, $orderItemsData) {
            $order = Order::create([
                'order_number' => 'ORD-'.strtoupper(Str::random(10)),
                'user_id' => $request->user()->id,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'subtotal' => $subtotal,
                'total' => $total,
                'shipping_address' => $request->shipping_address,
                'billing_address' => $request->billing_address ?? $request->shipping_address,
            ]);

            foreach ($orderItemsData as $data) {
                $order->items()->create($data);
            }

            return $order;
        });

        return new OrderResource($order->load('items'));
    }

    #[OA\Post(
        path: "/api/v1/orders/{id}/cancel",
        summary: "Cancel Order",
        description: "Cancel a pending order belonging to the user",
        security: [["bearerAuth" => []]],
        tags: ["Orders"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "Order cancelled successfully")]
    #[OA\Response(response: 400, description: "Cannot cancel order (not pending)")]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    #[OA\Response(response: 401, ref: "#/components/responses/ErrorResponse")]
    public function cancel(Request $request, $id)
    {
        $order = $request->user()->orders()->findOrFail($id);

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Cannot cancel order'], 400);
        }

        $order->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Order cancelled']);
    }
}
