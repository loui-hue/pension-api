<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Table;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::with(['user', 'table', 'items.product'])
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($orders);
    }

    public function store(Request $request)
    {
        $request->validate([
            'table_id' => 'required|exists:tables,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        $order = Order::create([
            'user_id' => $request->user()->id,
            'table_id' => $request->table_id,
            'status' => 'pendiente',
            'notes' => $request->notes,
            'total' => 0,
        ]);

        $total = 0;
        foreach ($request->items as $item) {
            $product = Product::findOrFail($item['product_id']);
            $subtotal = $product->price * $item['quantity'];
            $total += $subtotal;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $product->price,
                'subtotal' => $subtotal,
                'notes' => $item['notes'] ?? null,
            ]);
        }

        $order->update(['total' => $total]);
        Table::findOrFail($request->table_id)->update(['status' => 'ocupada']);

        return response()->json($order->load(['user', 'table', 'items.product']), 201);
    }

    public function show($id)
    {
        $order = Order::with(['user', 'table', 'items.product', 'payment'])
            ->findOrFail($id);
        return response()->json($order);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'table_id' => 'sometimes|required|exists:tables,id',
            'notes' => 'nullable|string',
        ]);

        $order = Order::findOrFail($id);
        $order->update($validated);

        return response()->json($order->load(['user', 'table', 'items.product']));
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pendiente,en_preparacion,listo,entregado,pagado,cancelado',
        ]);

        $order = Order::findOrFail($id);
        $order->update(['status' => $request->status]);

        if (in_array($request->status, ['pagado', 'cancelado'])) {
            Table::findOrFail($order->table_id)->update(['status' => 'disponible']);
        }

        return response()->json($order->load(['user', 'table', 'items.product']));
    }

    public function destroy($id)
    {
        $order = Order::findOrFail($id);
        $order->delete();
        return response()->json(['message' => 'Pedido eliminado']);
    }

    public function myOrders(Request $request)
    {
        $orders = Order::with(['table', 'items.product'])
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($orders);
    }
}