<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Table;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::with(['user', 'waiter', 'table', 'items.product'])
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
            'user_id' => 'nullable|exists:users,id',
        ]);

        // El comensal es quien acumula XP al pagar. Si el pedido lo hace un cliente
        // desde su app, es él mismo; si lo registra el personal, debe elegir al comensal.
        $creador = $request->user();
        if ($creador->hasRole('cliente')) {
            $comensalId = $creador->id;
            $meseroId = null;
        } else {
            $esComensal = $request->filled('user_id') && User::whereKey($request->user_id)
                ->whereHas('roles', fn ($q) => $q->where('name', 'cliente'))
                ->exists();

            if (!$esComensal) {
                return response()->json([
                    'message' => 'Debe seleccionar un comensal registrado para el pedido',
                    'errors' => ['user_id' => ['Debe seleccionar un comensal registrado']],
                ], 422);
            }

            $comensalId = (int) $request->user_id;
            $meseroId = $creador->id;
        }

        $order = DB::transaction(function () use ($request, $comensalId, $meseroId) {
            $order = Order::create([
                'user_id' => $comensalId,
                'waiter_id' => $meseroId,
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

            return $order;
        });

        return response()->json($order->load(['user', 'waiter', 'table', 'items.product']), 201);
    }

    public function show($id)
    {
        $order = Order::with(['user', 'waiter', 'table', 'items.product', 'payment'])
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

        return response()->json($order->load(['user', 'waiter', 'table', 'items.product']));
    }

    /**
     * Estados que cada rol puede asignar. "pagado" no está en ninguno: solo se
     * llega por el cobro (PaymentController), que además acredita el XP.
     */
    private const ESTADOS_POR_ROL = [
        'cocina' => ['en_preparacion', 'listo'],
        'mesero' => ['en_preparacion', 'entregado', 'cancelado'],   // "Enviar a cocina", entregar, cancelar
        'caja' => ['en_preparacion', 'entregado', 'cancelado'],
        'administrador' => ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado'],
    ];

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pendiente,en_preparacion,listo,entregado,cancelado',
        ], [
            'status.in' => 'Estado no válido. Un pedido se marca como pagado solo al cobrarlo en caja.',
        ]);

        $permitidos = collect(self::ESTADOS_POR_ROL)
            ->only($request->user()->roles->pluck('name'))
            ->flatten()
            ->unique();
        if (!$permitidos->contains($request->status)) {
            return response()->json([
                'message' => 'Tu rol no puede cambiar el pedido a "' . str_replace('_', ' ', $request->status) . '"',
            ], 403);
        }

        $order = Order::findOrFail($id);
        if (in_array($order->status, ['pagado', 'cancelado'])) {
            return response()->json(['message' => "El pedido #{$order->id} ya está {$order->status}"], 422);
        }

        $order->update(['status' => $request->status]);

        if ($request->status === 'cancelado') {
            Table::findOrFail($order->table_id)->update(['status' => 'disponible']);
        }

        return response()->json($order->load(['user', 'waiter', 'table', 'items.product']));
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