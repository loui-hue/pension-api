<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    private const MENSAJES = [
        'name.required' => 'Escribe el nombre de la categoría.',
        'name.max' => 'El nombre es demasiado largo (máximo 255 caracteres).',
        'name.unique' => 'Ya existe una categoría con ese nombre.',
    ];

    public function index()
    {
        $categories = Category::where('active', true)->withCount('products')->orderBy('id')->get();
        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string',
            'active' => 'boolean',
        ], self::MENSAJES);

        $category = Category::create($validated);
        return response()->json($category, 201);
    }

    public function show($id)
    {
        $category = Category::findOrFail($id);
        return response()->json($category);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:categories,name,' . $id,
            'description' => 'nullable|string',
            'active' => 'boolean',
        ], self::MENSAJES);

        $category = Category::findOrFail($id);
        $category->update($validated);
        return response()->json($category);
    }

    public function destroy($id)
    {
        $category = Category::findOrFail($id);
        // Borrar la categoría borraría en cascada sus productos.
        $productos = $category->products()->count();
        if ($productos > 0) {
            return response()->json(['message' => "La categoría «{$category->name}» tiene {$productos} producto(s): muévelos a otra categoría o elimínalos antes."], 422);
        }
        $category->delete();
        return response()->json(['message' => "Categoría «{$category->name}» eliminada"]);
    }
}