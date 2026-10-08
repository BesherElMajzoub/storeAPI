<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/dashboard',
        summary: 'Admin Dashboard Stats',
        description: 'Get general statistics for the admin dashboard',
        security: [['bearerAuth' => []]],
        tags: ['Admin Dashboard']
    )]
    #[OA\Response(
        response: 200,
        description: 'Dashboard stats fetched successfully',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'month_sales_total', type: 'number', format: 'float', example: 1540.50),
                new OA\Property(property: 'current_orders_count', type: 'integer', example: 25, description: 'Orders to fulfil. Same as GET /admin/orders?status=processing&payment_status=authorized,paid'),
                new OA\Property(property: 'users_count', type: 'integer', example: 150),
                new OA\Property(
                    property: 'top_products',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/Product')
                ),
                new OA\Property(
                    property: 'latest_orders',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/Order')
                ),
                new OA\Property(
                    property: 'filters',
                    type: 'object',
                    description: 'Query string of the list that returns each count, so a tile can link to it.',
                    additionalProperties: new OA\AdditionalProperties(type: 'string')
                ),
                new OA\Property(
                    property: 'alerts',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'low_stock', type: 'integer', example: 3, description: 'Same as GET /admin/products?low_stock=1 (stock_qty < 3)'),
                        new OA\Property(property: 'pending_orders', type: 'integer', example: 5, description: 'Awaiting payment. Same as GET /admin/orders?status=pending_payment'),
                        new OA\Property(property: 'payment_holds', type: 'integer', example: 0, description: 'Payment could not be captured; do not ship. Same as GET /admin/orders?status=processing&payment_status=failed'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/UnauthorizedResponse')]
    #[OA\Response(response: 403, ref: '#/components/responses/ForbiddenResponse')]
    public function index()
    {
        return response()->json([
            // 'visitors_today' => 100, // Placeholder
            'month_sales_total' => Order::where('created_at', '>=', now()->startOfMonth())
                ->whereNotIn('status', ['cancelled', 'refunded', 'pending_payment'])
                ->sum('total'),
            // Paid or card-held orders that still have to be shipped.
            'current_orders_count' => Order::where('status', 'processing')->whereIn('payment_status', ['authorized', 'paid'])->count(),
            'users_count' => User::count(),
            'top_products' => Product::withCount('reviews')->orderBy('reviews_count', 'desc')->take(5)->get(), // or sold count
            'latest_orders' => Order::latest()->take(5)->get(),
            'alerts' => [
                'low_stock' => Product::where('stock_qty', '<', Product::LOW_STOCK_THRESHOLD)->count(),
                'pending_orders' => Order::where('status', 'pending_payment')->count(),
                'payment_holds' => Order::where('status', 'processing')->where('payment_status', 'failed')->count(),
            ],
            'filters' => [
                'current_orders_count' => 'status=processing&payment_status=authorized,paid',
                'low_stock' => 'low_stock=1',
                'pending_orders' => 'status=pending_payment',
                'payment_holds' => 'status=processing&payment_status=failed',
            ],
        ]);
    }
}
