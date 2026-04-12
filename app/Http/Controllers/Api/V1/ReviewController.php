<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReviewRequest;
use App\Http\Requests\Api\V1\UpdateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Product;
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

    #[OA\Post(
        path: '/api/v1/products/{product}/reviews',
        summary: 'Create Review',
        description: 'Submit a review for a product. One review per user per product.',
        security: [['bearerAuth' => []]],
        tags: ['Reviews']
    )]
    #[OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['rating'],
            properties: [
                new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5, example: 4),
                new OA\Property(property: 'comment', type: 'string', example: 'Great product, highly recommend!'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Review submitted successfully')]
    #[OA\Response(response: 409, description: 'Already reviewed this product')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    public function store(StoreReviewRequest $request, Product $product): JsonResponse
    {
        // Ensure product is published
        if ($product->status !== 'published') {
            return $this->error('Product not found.', 404);
        }

        try {
            $review = $this->reviewService->create(
                user: $request->user(),
                product: $product,
                data: $request->validated(),
                ipAddress: $request->ip(),
            );

            return $this->success(
                new ReviewResource($review->load('user')),
                'Review submitted. It will be visible after moderation.',
                201
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    #[OA\Get(
        path: '/api/v1/products/{product}/my-review',
        summary: 'Get My Review',
        description: "Get the authenticated user's review for a specific product",
        security: [['bearerAuth' => []]],
        tags: ['Reviews']
    )]
    #[OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Review fetched')]
    #[OA\Response(response: 404, description: 'No review found')]
    public function myReview(Request $request, Product $product): JsonResponse
    {
        $review = Review::where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->with('user')
            ->first();

        if (!$review) {
            return $this->error('You have not reviewed this product yet.', 404);
        }

        return $this->success(new ReviewResource($review), 'Review fetched.');
    }

    #[OA\Put(
        path: '/api/v1/products/{product}/reviews/{review}',
        summary: 'Update Review',
        description: 'Update your own review. Edit window is 30 days from creation.',
        security: [['bearerAuth' => []]],
        tags: ['Reviews']
    )]
    #[OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'review', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5),
                new OA\Property(property: 'comment', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Review updated')]
    #[OA\Response(response: 403, description: 'Not authorized or edit window expired')]
    public function update(UpdateReviewRequest $request, Product $product, Review $review): JsonResponse
    {
        // Ensure review belongs to this product
        if ($review->product_id !== $product->id) {
            return $this->error('Review not found.', 404);
        }

        $this->authorize('update', $review);

        $updatedReview = $this->reviewService->update($review, $request->validated());

        return $this->success(
            new ReviewResource($updatedReview->load('user')),
            'Review updated. It will be re-reviewed by our team.'
        );
    }

    #[OA\Delete(
        path: '/api/v1/products/{product}/reviews/{review}',
        summary: 'Delete Review',
        description: 'Delete your own review.',
        security: [['bearerAuth' => []]],
        tags: ['Reviews']
    )]
    #[OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'review', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Review deleted')]
    #[OA\Response(response: 403, description: 'Not authorized')]
    public function destroy(Product $product, Review $review): JsonResponse
    {
        if ($review->product_id !== $product->id) {
            return $this->error('Review not found.', 404);
        }

        $this->authorize('delete', $review);

        $this->reviewService->delete($review);

        return $this->success(null, 'Review deleted successfully.');
    }
}
