<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    use \App\Traits\LogsActivity;

    public function index(Request $request)
    {
        $products = Product::latest()->paginate(20);
        return response()->json($products);
    }

    public function show($id)
    {
        return response()->json(Product::with(['variants', 'images'])->findOrFail($id));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required',
            'price' => 'required|numeric',
            'category_id' => 'required|exists:categories,id',
        ]);
        
        $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(6);

        $product = Product::create($validated);
        
        // Handle images/variants if passed
        
        return response()->json($product, 201);
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        $oldIndex = $product->toArray();
        $product->update($request->all());
        
        $this->logActivity('update_product', "Updated product {$product->name}", [
            'before' => $oldIndex,
            'after' => $product->toArray()
        ]);
        
        return response()->json($product);
    }

    public function destroy($id)
    {
        Product::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
