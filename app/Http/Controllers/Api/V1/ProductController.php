<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->published()
            ->with(['category', 'images'])
            ->filter($request->all())
            ->sort($request->get('sort', 'newest'))
            ->paginate($request->get('per_page', 20));

        return ProductResource::collection($products);
    }

    public function show($slug)
    {
        $product = Product::where('slug', $slug)
            ->published()
            ->with(['category', 'images', 'variants', 'reviews.user'])
            ->firstOrFail();

        return new ProductResource($product);
    }

    public function reviews($id)
    {
        $product = Product::findOrFail($id);
        
        $reviews = $product->reviews()
            ->where('is_approved', true)
            ->with('user')
            ->latest()
            ->paginate(10);
            
        return response()->json($reviews); // Or ReviewResource
    }
}
