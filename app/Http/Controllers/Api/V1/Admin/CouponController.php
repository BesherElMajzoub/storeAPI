<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreCouponRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCouponRequest;
use App\Http\Resources\CouponResource;
use App\Http\Resources\CouponUsageResource;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CouponController extends Controller
{
    // ── List Coupons ─────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/coupons',
        summary: 'Admin – List Coupons',
        description: 'Paginated list of coupons for admin. Supports search by code, filtering by type, is_active, and status (active/expired/scheduled). Sorted by latest by default.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Coupons']
    )]
    #[OA\Parameter(name: 'search', in: 'query', description: 'Search coupon code', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'type', in: 'query', description: 'Filter by type (percentage/fixed)', required: false, schema: new OA\Schema(type: 'string', enum: ['percentage', 'fixed']))]
    #[OA\Parameter(name: 'is_active', in: 'query', description: 'Filter by active flag', required: false, schema: new OA\Schema(type: 'boolean'))]
    #[OA\Parameter(name: 'status', in: 'query', description: 'Filter by status (active/expired/scheduled)', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'expired', 'scheduled']))]
    #[OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 15))]
    #[OA\Response(
        response: 200,
        description: 'Coupons listed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Coupons retrieved successfully.'),
                new OA\Property(property: 'data', type: 'object'),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
            ]
        )
    )]
    public function index(Request $request): JsonResponse
    {
        $query = Coupon::query();

        // Search by code
        if ($request->filled('search')) {
            $query->where('code', 'like', '%' . $request->search . '%');
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter by is_active
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by status
        if ($request->filled('status')) {
            $now = now();
            $status = $request->status;

            if ($status === 'active') {
                $query->where('is_active', true)
                    ->where(function ($q) use ($now) {
                        $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                    })
                    ->where(function ($q) use ($now) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
                    });
            } elseif ($status === 'expired') {
                $query->whereNotNull('expires_at')->where('expires_at', '<', $now);
            } elseif ($status === 'scheduled') {
                $query->whereNotNull('starts_at')->where('starts_at', '>', $now);
            }
        }

        $perPage = $request->integer('per_page', 15);
        $coupons = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Coupons retrieved successfully.',
            'data'    => CouponResource::collection($coupons)->response()->getData(true),
            'errors'  => null,
        ]);
    }

    // ── Create Coupon ────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/v1/admin/coupons',
        summary: 'Admin – Create Coupon',
        description: 'Create a new coupon in the system. The code will be auto-uppercased.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Coupons']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['code', 'type', 'value'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'WELCOME20'),
                new OA\Property(property: 'type', type: 'string', enum: ['percentage', 'fixed'], example: 'percentage'),
                new OA\Property(property: 'value', type: 'number', format: 'float', example: 20.00),
                new OA\Property(property: 'minimum_order_amount', type: 'number', format: 'float', nullable: true, example: 50.00),
                new OA\Property(property: 'maximum_discount_amount', type: 'number', format: 'float', nullable: true, example: 100.00),
                new OA\Property(property: 'usage_limit', type: 'integer', nullable: true, example: 100),
                new OA\Property(property: 'usage_limit_per_user', type: 'integer', nullable: true, example: 1),
                new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true, example: '2026-06-01T00:00:00Z'),
                new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true, example: '2026-12-31T23:59:59Z'),
                new OA\Property(property: 'is_active', type: 'boolean', example: true)
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Coupon created successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Coupon created successfully.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Coupon'),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
            ]
        )
    )]
    public function store(StoreCouponRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['code'] = strtoupper($data['code']);

        // Check uniqueness again after uppercasing
        if (Coupon::where('code', $data['code'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data'    => null,
                'errors'  => [
                    'code' => ['The coupon code has already been taken.']
                ]
            ], 422);
        }

        $coupon = Coupon::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Coupon created successfully.',
            'data'    => new CouponResource($coupon),
            'errors'  => null,
        ], 201);
    }

    // ── Show Coupon ──────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/coupons/{id}',
        summary: 'Admin – Show Coupon Details',
        description: 'Show details of a single coupon along with usage statistics.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Coupons']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Coupon details fetched',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Coupon details retrieved successfully.'),
                new OA\Property(property: 'data', type: 'object'),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
            ]
        )
    )]
    public function show(int $id): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Coupon details retrieved successfully.',
            'data'    => array_merge((new CouponResource($coupon))->toArray(request()), [
                'total_discount_given' => round((float) $coupon->usages()->sum('discount_amount'), 2),
                'total_orders_used'    => (int) $coupon->usages()->distinct('order_id')->count(),
            ]),
            'errors'  => null,
        ]);
    }

    // ── Update Coupon ────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/v1/admin/coupons/{id}',
        summary: 'Admin – Update Coupon',
        description: 'Update the coupon details. The code will be auto-uppercased. Manually changing used_count is prohibited.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Coupons']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['code', 'type', 'value'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'WELCOME20'),
                new OA\Property(property: 'type', type: 'string', enum: ['percentage', 'fixed'], example: 'percentage'),
                new OA\Property(property: 'value', type: 'number', format: 'float', example: 25.00),
                new OA\Property(property: 'minimum_order_amount', type: 'number', format: 'float', nullable: true, example: 50.00),
                new OA\Property(property: 'maximum_discount_amount', type: 'number', format: 'float', nullable: true, example: 100.00),
                new OA\Property(property: 'usage_limit', type: 'integer', nullable: true, example: 100),
                new OA\Property(property: 'usage_limit_per_user', type: 'integer', nullable: true, example: 1),
                new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true, example: '2026-06-01T00:00:00Z'),
                new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true, example: '2026-12-31T23:59:59Z'),
                new OA\Property(property: 'is_active', type: 'boolean', example: true)
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Coupon updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Coupon updated successfully.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Coupon'),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
            ]
        )
    )]
    public function update(UpdateCouponRequest $request, int $id): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);
        $data = $request->validated();
        $data['code'] = strtoupper($data['code']);

        // Check uniqueness again after uppercasing
        if (Coupon::where('code', $data['code'])->where('id', '!=', $id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data'    => null,
                'errors'  => [
                    'code' => ['The coupon code has already been taken.']
                ]
            ], 422);
        }

        // Prevent modifying used_count manually
        unset($data['used_count']);

        $coupon->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Coupon updated successfully.',
            'data'    => new CouponResource($coupon),
            'errors'  => null,
        ]);
    }

    // ── Delete Coupon ────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/v1/admin/coupons/{id}',
        summary: 'Admin – Delete Coupon',
        description: 'Hard delete a coupon only if it has never been used. If used, returns a validation error requesting the admin to disable it instead.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Coupons']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Coupon deleted successfully'
    )]
    #[OA\Response(
        response: 422,
        description: 'Coupon cannot be deleted because it has been used'
    )]
    public function destroy(int $id): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);

        if ($coupon->used_count > 0 || $coupon->usages()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data'    => null,
                'errors'  => [
                    'coupon' => ['This coupon has already been used and cannot be deleted. Please deactivate it instead.']
                ]
            ], 422);
        }

        $coupon->delete();

        return response()->json([
            'success' => true,
            'message' => 'Coupon deleted successfully.',
            'data'    => null,
            'errors'  => null,
        ]);
    }

    // ── Toggle Coupon Status ─────────────────────────────────────────────────

    #[OA\Patch(
        path: '/api/v1/admin/coupons/{id}/toggle-status',
        summary: 'Admin – Toggle Coupon Status',
        description: 'Toggle the is_active status of a coupon.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Coupons']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Coupon status toggled successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Coupon status updated successfully.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Coupon'),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
            ]
        )
    )]
    public function toggleStatus(int $id): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->is_active = !$coupon->is_active;
        $coupon->save();

        return response()->json([
            'success' => true,
            'message' => 'Coupon status updated successfully.',
            'data'    => new CouponResource($coupon),
            'errors'  => null,
        ]);
    }

    // ── List Coupon Usages ───────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/coupons/{id}/usages',
        summary: 'Admin – List Coupon Usages',
        description: 'Paginated list of usages for a specific coupon.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Coupons']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 15))]
    #[OA\Response(
        response: 200,
        description: 'Coupon usages listed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Coupon usages retrieved successfully.'),
                new OA\Property(property: 'data', type: 'object'),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
            ]
        )
    )]
    public function usages(int $id, Request $request): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);
        $perPage = $request->integer('per_page', 15);
        $usages = $coupon->usages()->with(['order', 'user'])->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Coupon usages retrieved successfully.',
            'data'    => CouponUsageResource::collection($usages)->response()->getData(true),
            'errors'  => null,
        ]);
    }
}
