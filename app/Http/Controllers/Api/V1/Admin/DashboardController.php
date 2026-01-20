<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        return response()->json([
            'visitors_today' => 100, // Placeholder
            'month_sales_total' => Order::where('created_at', '>=', now()->startOfMonth())->sum('total'),
            'current_orders_count' => Order::where('status', 'pending')->count(),
            'users_count' => User::count(),
            'top_products' => Product::withCount('reviews')->orderBy('reviews_count', 'desc')->take(5)->get(), // or sold count
            'latest_orders' => Order::latest()->take(5)->get(),
            'alerts' => [
                'low_stock' => Product::where('stock_qty', '<', 5)->count(),
                'pending_orders' => Order::where('status', 'pending')->count(),
            ]
        ]);
    }
}
