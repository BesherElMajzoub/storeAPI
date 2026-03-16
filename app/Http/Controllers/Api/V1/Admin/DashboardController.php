<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    #[OA\Get(
        path: "/api/v1/admin/dashboard",
        summary: "Admin Dashboard Stats",
        description: "Get general statistics for the admin dashboard",
        security: [["bearerAuth" => []]],
        tags: ["Admin Dashboard"]
    )]
    #[OA\Response(
        response: 200,
        description: "Dashboard stats fetched successfully",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "month_sales_total", type: "number", format: "float", example: 1540.50),
                new OA\Property(property: "current_orders_count", type: "integer", example: 25),
                new OA\Property(property: "users_count", type: "integer", example: 150),
                new OA\Property(
                    property: "top_products",
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/Product")
                ),
                new OA\Property(
                    property: "latest_orders",
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/Order")
                ),
                new OA\Property(
                    property: "alerts",
                    type: "object",
                    properties: [
                        new OA\Property(property: "low_stock", type: "integer", example: 3),
                        new OA\Property(property: "pending_orders", type: "integer", example: 5)
                    ]
                )
            ]
        )
    )]
    #[OA\Response(response: 401, ref: "#/components/responses/ErrorResponse")]
    #[OA\Response(response: 403, ref: "#/components/responses/ErrorResponse")]
    public function index()
    {
        return response()->json([
            // 'visitors_today' => 100, // Placeholder
            'month_sales_total' => Order::where('created_at', '>=', now()->startOfMonth())->sum('total'),
            'current_orders_count' => Order::where('status', 'pending')->count(),
            'users_count' => User::count(),
            'top_products' => Product::withCount('reviews')->orderBy('reviews_count', 'desc')->take(5)->get(), // or sold count
            'latest_orders' => Order::latest()->take(5)->get(),
            'alerts' => [
                'low_stock' => Product::where('stock_qty', '<', 3)->count(),
                'pending_orders' => Order::where('status', 'pending')->count(),
            ]
        ]);
    }
}
