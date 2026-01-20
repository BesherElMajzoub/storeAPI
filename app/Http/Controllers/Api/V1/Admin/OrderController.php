<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::with('user')->latest()->paginate(20);
        return response()->json($orders);
    }

    public function show($id)
    {
        return response()->json(Order::with(['items', 'user', 'payment'])->findOrFail($id));
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:pending,processing,shipped,delivered,cancelled']);
        
        $order = Order::findOrFail($id);
        $order->update(['status' => $request->status]);
        
        // Log activity or send notification
        
        return response()->json($order);
    }
}
