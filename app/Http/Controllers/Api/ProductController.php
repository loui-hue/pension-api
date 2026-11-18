<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    private const MENSAJES = [
        'name.required' => 'Escribe el nombre del producto.',
        'name.max' => 'El nombre es demasiado largo (máximo 255 caracteres).',
        'price.required' => 'Escribe el precio.',
        'price.numeric' => 'El precio debe ser un número.',
        'price.min' => 'El precio no puede ser negativo.',
        'price.max' => 'El precio no puede pasar de Bs. 10 000.',
        'category_id.required' => 'Elige una categoría.',
        'category_id.exists' => 'La categoría elegida no existe.',
        'day_of_week.between' => 'El día del menú debe ser de lunes (1) a sábado (6).',
    ];

    /**
     * Gestión del administrador: todos los productos, también los no disponibles
     * y los menús de otros días (la carta del cliente solo muestra los de hoy).
     */
    public function gestion()
    {
        $products = Product::with('category')->withCount('orderItems as veces_pedido')
            ->orderBy('category_id')->orderBy('id')->get();
        return response()->json($products);
    }

    public function index()
    {
        // Solo lo que se puede pedir hoy (el menú del día cambia según el día).
        $products = Product::with('category')->disponiblesHoy()->orderBy('category_id')->orderBy('id')->get();
        return response()->json($products);
    }

    /**
     * Menú del día de toda la semana (lunes a sábado), solo para consultar:
     * se puede pedir únicamente el del día (ver index y OrderController).
     */
    public function menuSemana()
    {
        $menu = Product::with('category')
            ->where('available', true)
            ->whereNotNull('day_of_week')
            ->orderBy('day_of_week')->orderBy('id')
            ->get();
        return response()->json($menu);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0|max:10000',
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'available' => 'boolean',
            'image' => 'nullable|string',
            'day_of_week' => 'nullable|integer|between:1,6',
        ], self::MENSAJES);

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
            'price' => 'sometimes|required|numeric|min:0|max:10000',
            'category_id' => 'sometimes|required|exists:categories,id',
            'description' => 'nullable|string',
            'available' => 'boolean',
            'image' => 'nullable|string',
            'day_of_week' => 'nullable|integer|between:1,6',
        ], self::MENSAJES);

        $product = Product::findOrFail($id);
        $product->update($validated);
        return response()->json($product->load('category'));
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        // Borrar el producto borraría en cascada los platos de los pedidos ya
        // registrados (y sus totales dejarían de cuadrar en los reportes).
        if ($product->orderItems()->exists()) {
            return response()->json([
                'message' => "«{$product->name}» ya figura en pedidos registrados: no se puede eliminar. Márcalo como no disponible para que no se pueda pedir.",
            ], 422);
        }
        $product->delete();
        return response()->json(['message' => "Producto «{$product->name}» eliminado"]);
    }
}