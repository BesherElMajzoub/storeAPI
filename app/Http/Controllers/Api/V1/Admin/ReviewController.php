<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Services\ReviewService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ReviewController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ReviewService $reviewService) {}

    #[OA\Get(
        path: '/api/v1/admin/reviews',
        summary: 'Admin - List Reviews',
        description: 'Get all reviews with filters. Admin only.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Reviews']
    )]
    #[OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected']))]
    #[OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: 'Reviews fetched')]
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $query   = Review::with(['user', 'product']);

        // Filter by approval status
        if ($request->has('status')) {
            match ($request->status) {
                'pending'  => $query->where('is_approved', false)->whereNull('admin_note'),
                'approved' => $query->where('is_approved', true),
                'rejected' => $query->where('is_approved', false)->whereNotNull('admin_note'),
                default    => null,
            };
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $reviews = $query->latest()->paginate($perPage);

        return $this->success(
            ReviewResource::collection($reviews)->response()->getData(true),
            'Reviews fetched.'
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/reviews/{review}',
        summary: 'Admin - Get Review',
        description: 'Get a single review by ID. Admin only.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Reviews']
    )]
    #[OA\Parameter(name: 'review', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Review fetched')]
    public function show(Review $review): JsonResponse
    {
        return $this->success(
            new ReviewResource($review->load(['user', 'product'])),
            'Review fetched.'
        );
    }

    #[OA\Patch(
        path: '/api/v1/admin/reviews/{review}/moderate',
        summary: 'Admin - Moderate Review',
        description: 'Approve or reject a review. Updates product rating automatically.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Reviews']
    )]
    #[OA\Parameter(name: 'review', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['action'],
            properties: [
                new OA\Property(property: 'action', type: 'string', enum: ['approve', 'reject'], example: 'approve'),
                new OA\Property(property: 'admin_note', type: 'string', example: 'Review violates content policy.'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Review moderated')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    public function moderate(Request $request, Review $review): JsonResponse
    {
        $data = $request->validate([
            'action'     => 'required|in:approve,reject',
            'admin_note' => 'nullable|string|max:500',
        ]);

        $updated = $this->reviewService->moderate(
            review:    $review,
            action:    $data['action'],
            adminNote: $data['admin_note'] ?? null,
        );

        $verb    = $data['action'] === 'approve' ? 'approved' : 'rejected';
        $message = "Review {$verb} successfully. Product rating updated.";

        return $this->success(new ReviewResource($updated->load(['user', 'product'])), $message);
    }

    #[OA\Delete(
        path: '/api/v1/admin/reviews/{review}',
        summary: 'Admin - Delete Review',
        description: 'Permanently delete a review. Admin only.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Reviews']
    )]
    #[OA\Parameter(name: 'review', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Review deleted')]
    public function destroy(Review $review): JsonResponse
    {
        $this->reviewService->delete($review);

        return $this->success(null, 'Review deleted and product rating updated.');
    }
}
