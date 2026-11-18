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

        if ($error = $this->rechazarSiNoSonDeHoy($request->items)) {
            return $error;
        }

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

            $this->guardarPlatos($order, $request->items);
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

    /**
     * Modifica un pedido: mesa, notas y, mientras siga "pendiente" (aún no pasó a
     * cocina), también sus platos. Es el cambio de último momento del comensal:
     * agregar, quitar o cambiar cantidades. Los subtotales y el total se recalculan
     * con el precio vigente y solo se aceptan productos de la carta de hoy.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'table_id' => 'sometimes|required|exists:tables,id',
            'notes' => 'nullable|string',
            'items' => 'sometimes|required|array|min:1',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|integer|min:1',
        ]);

        Order::findOrFail($id);
        if ($request->has('items') && ($error = $this->rechazarSiNoSonDeHoy($request->items))) {
            return $error;
        }

        // Con el pedido bloqueado: si el mesero lo modifica mientras otro lo envía a
        // cocina, el cambio de platos espera y luego ve el estado real.
        return DB::transaction(function () use ($id, $validated, $request) {
            $order = Order::lockForUpdate()->findOrFail($id);
            if ($request->has('items') && $order->status !== 'pendiente') {
                return response()->json([
                    'message' => "El pedido #{$order->id} ya está " . (self::NOMBRE_ESTADO[$order->status] ?? $order->status)
                        . ': solo se pueden cambiar los platos mientras está pendiente, antes de enviarlo a cocina',
                ], 422);
            }

            $mesaAnterior = $order->table_id;
            $order->update(collect($validated)->only(['table_id', 'notes'])->all());

            if ($request->has('items')) {
                $order->items()->delete();
                $this->guardarPlatos($order, $request->items);
            }

            if ((int) $order->table_id !== (int) $mesaAnterior) {
                Table::whereKey($mesaAnterior)->update(['status' => 'disponible']);
                Table::whereKey($order->table_id)->update(['status' => 'ocupada']);
            }

            return response()->json($order->fresh()->load(['user', 'waiter', 'table', 'items.product']));
        });
    }

    /** Solo se piden productos de la carta de hoy (el menú del día de otro día no). */
    private function rechazarSiNoSonDeHoy(array $items)
    {
        $idsPedidos = collect($items)->pluck('product_id')->unique();
        $deHoy = Product::disponiblesHoy()->whereIn('id', $idsPedidos)->pluck('id');
        $noDisponibles = Product::whereIn('id', $idsPedidos->diff($deHoy))->pluck('name');
        if ($noDisponibles->isEmpty()) {
            return null;
        }

        return response()->json([
            'message' => 'No disponible hoy: ' . $noDisponibles->join(', '),
            'errors' => ['items' => ['Hay productos que no están en la carta de hoy']],
        ], 422);
    }

    /** Crea los platos del pedido con el precio vigente y recalcula el total. */
    private function guardarPlatos(Order $order, array $items): void
    {
        $total = 0;
        foreach ($items as $item) {
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
    }

    /**
     * Estados que cada rol puede asignar. "pagado" no está en ninguno: solo se
     * llega por el cobro (PaymentController), que además acredita el XP.
     */
    private const NOMBRE_ESTADO = [
        'en_preparacion' => 'en preparación', 'listo' => 'listo', 'entregado' => 'entregado',
        'pagado' => 'pagado', 'cancelado' => 'cancelado',
    ];

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

        // Con el pedido bloqueado (igual que al cobrar): un pedido no puede quedar
        // cancelado y pagado a la vez si caja y mesero actúan al mismo tiempo.
        return DB::transaction(function () use ($id, $request) {
            $order = Order::lockForUpdate()->findOrFail($id);
            if (in_array($order->status, ['pagado', 'cancelado'])) {
                return response()->json(['message' => "El pedido #{$order->id} ya está {$order->status}"], 422);
            }

            $order->update(['status' => $request->status]);

            if ($request->status === 'cancelado') {
                Table::findOrFail($order->table_id)->update(['status' => 'disponible']);
            }

            return response()->json($order->load(['user', 'waiter', 'table', 'items.product']));
        });
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