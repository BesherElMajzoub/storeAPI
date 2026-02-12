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

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = $request->user()->orders()
            ->latest()
            ->paginate(10);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, $id)
    {
        $order = $request->user()->orders()
            ->with(['items.product', 'payment'])
            ->findOrFail($id);

        return new OrderResource($order);
    }

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
