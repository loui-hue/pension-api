<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index()
    {
        // Solo lo que se puede pedir hoy (el menú del día cambia según el día).
        $products = Product::with('category')->disponiblesHoy()->orderBy('category_id')->orderBy('id')->get();
        return response()->json($products);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'available' => 'boolean',
            'image' => 'nullable|string',
            'day_of_week' => 'nullable|integer|between:1,6',
        ]);

        $product = Product::create($validated);
        return response()->json($product->load('category'), 201);
    }

    public function show($id)
    {
        $product = Product::with('category')->findOrFail($id);
        return response()->json($product);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'price' => 'sometimes|required|numeric|min:0',
            'category_id' => 'sometimes|required|exists:categories,id',
            'description' => 'nullable|string',
            'available' => 'boolean',
            'image' => 'nullable|string',
            'day_of_week' => 'nullable|integer|between:1,6',
        ]);

        $product = Product::findOrFail($id);
        $product->update($validated);
        return response()->json($product->load('category'));
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();
        return response()->json(['message' => 'Producto eliminado']);
    }
}