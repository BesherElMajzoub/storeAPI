<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ReviewResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only([
            'search',
            'category',
            'price_min',
            'price_max',
            'rating',
            'in_stock',
        ]);
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $products = Product::query()
            ->published()
            ->with(['category', 'images'])
            ->filter($filters)
            ->sort($request->get('sort', 'newest'))
            ->paginate($perPage);

        return ProductResource::collection($products);
    }

    public function show($slug)
    {
        $product = Product::where('slug', $slug)
            ->published()
            ->with([
                'category',
                'images',
                'variants',
                'reviews' => function ($query) {
                    $query->where('is_approved', true)->with('user');
                },
            ])
            ->firstOrFail();

        return new ProductResource($product);
    }

    public function reviews($id)
    {
        $product = Product::published()->findOrFail($id);
        
        $reviews = $product->reviews()
            ->where('is_approved', true)
            ->with('user')
            ->latest()
            ->paginate(10);
            
        return ReviewResource::collection($reviews);
    }
}
