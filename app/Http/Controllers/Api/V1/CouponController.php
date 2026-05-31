<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ValidateCouponRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CouponService;
use App\Exceptions\CouponValidationException;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CouponController extends Controller
{
    protected CouponService $couponService;

    public function __construct(CouponService $couponService)
    {
        $this->couponService = $couponService;
    }

    #[OA\Post(
        path: '/api/v1/coupons/validate',
        summary: 'Validate Coupon',
        description: 'Validate a coupon code against a list of cart items and return the calculated discount details.',
        security: [['bearerAuth' => []]],
        tags: ['Coupons']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['code', 'items'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'SAVE50'),
                new OA\Property(
                    property: 'items',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'product_id', type: 'integer', example: 1),
                            new OA\Property(property: 'variant_id', type: 'integer', nullable: true, example: null),
                            new OA\Property(property: 'quantity', type: 'integer', example: 1)
                        ]
                    )
                )
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Coupon is valid. Discount details returned.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'code', type: 'string', example: 'SAVE50'),
                        new OA\Property(property: 'subtotal', type: 'number', format: 'float', example: 200.00),
                        new OA\Property(property: 'discount', type: 'number', format: 'float', example: 50.00),
                        new OA\Property(property: 'total', type: 'number', format: 'float', example: 150.00)
                    ]
                ),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null)
            ]
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation failed or coupon is invalid.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Coupon has expired.'),
                new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                new OA\Property(
                    property: 'errors',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'code',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            example: ['Coupon has expired.']
                        )
                    ]
                )
            ]
        )
    )]
    public function validate(ValidateCouponRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $items = $request->input('items');
        $subtotal = 0.0;

        foreach ($items as $item) {
            $product = Product::findOrFail($item['product_id']);
            $price = (float) $product->final_price;

            if (!empty($item['variant_id'])) {
                $variant = ProductVariant::find($item['variant_id']);
                if ($variant && $variant->price !== null) {
                    $price = (float) $variant->price;
                }
            }

            $subtotal += $price * (int) $item['quantity'];
        }

        try {
            $coupon = $this->couponService->validateCoupon($request->input('code'), $user, $subtotal);
            $discount = $this->couponService->calculateDiscount($coupon, $subtotal);
            $total = max(0.0, $subtotal - $discount);

            return response()->json([
                'success' => true,
                'data' => [
                    'code'     => $coupon->code,
                    'subtotal' => round($subtotal, 2),
                    'discount' => round($discount, 2),
                    'total'    => round($total, 2),
                ],
                'errors' => null,
            ]);
        } catch (CouponValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => null,
                'errors'  => [
                    'code' => [$e->getMessage()]
                ],
            ], 422);
        }
    }
}
