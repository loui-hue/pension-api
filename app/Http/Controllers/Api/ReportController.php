<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Streak;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    /**
     * Indicadores del Dashboard para un periodo (?from=YYYY-MM-DD&to=YYYY-MM-DD):
     * pedidos y ventas, productos más vendidos, frecuencia de visitas y
     * participación en el programa de fidelización.
     */
    public function dashboard(Request $request)
    {
        if (!$request->user()->hasRole('administrador')) {
            return response()->json(['message' => 'Solo el administrador puede ver los reportes'], 403);
        }

        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = $request->from ? Carbon::parse($request->from)->startOfDay() : now()->subDays(6)->startOfDay();
        $to = $request->to ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        $orders = Order::whereBetween('created_at', [$from, $to])->get();
        $pagados = $orders->where('status', 'pagado');

        // Pedidos y ventas por día (se rellenan los días sin movimiento con cero).
        $porDia = [];
        for ($dia = $from->copy(); $dia->lte($to); $dia->addDay()) {
            $porDia[$dia->toDateString()] = ['date' => $dia->toDateString(), 'orders' => 0, 'sales' => 0.0];
        }
        foreach ($orders as $order) {
            $fecha = $order->created_at->toDateString();
            if (!isset($porDia[$fecha])) {
                continue;
            }
            $porDia[$fecha]['orders']++;
            if ($order->status === 'pagado') {
                $porDia[$fecha]['sales'] += (float) $order->total;
            }
        }

        $ventas = (float) $pagados->sum('total');

        // Productos más vendidos (pedidos no cancelados).
        $topProductos = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', '!=', 'cancelado')
            ->groupBy('products.id', 'products.name')
            ->selectRaw('products.id as product_id, products.name as name, SUM(order_items.quantity) as quantity, SUM(order_items.subtotal) as revenue')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get()
            ->map(fn ($p) => [
                'product_id' => $p->product_id,
                'name' => $p->name,
                'quantity' => (int) $p->quantity,
                'revenue' => round((float) $p->revenue, 2),
            ]);

        // Frecuencia de visitas: una visita = un comensal con pedido pagado en un día.
        $clienteIds = User::whereHas('roles', fn ($q) => $q->where('name', 'cliente'))->pluck('id');
        $visitas = $pagados->whereIn('user_id', $clienteIds)
            ->groupBy('user_id')
            ->map(fn ($pedidos) => $pedidos->map(fn ($o) => $o->created_at->toDateString())->unique()->count());

        $nombres = User::whereIn('id', $visitas->keys())->pluck('name', 'id');
        $frecuentes = $visitas->sortDesc()->take(5)->map(fn ($n, $userId) => [
            'user_id' => $userId,
            'name' => $nombres[$userId] ?? '—',
            'visits' => $n,
        ])->values();

        $totalVisitas = $visitas->sum();
        $comensalesUnicos = $visitas->count();

        // Fidelización.
        $registrados = $clienteIds->count();
        $participantes = LoyaltyPoint::whereBetween('created_at', [$from, $to])
            ->whereIn('user_id', $clienteIds)
            ->distinct()
            ->count('user_id');

        return response()->json([
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'summary' => [
                'orders' => $orders->count(),
                'paid_orders' => $pagados->count(),
                'cancelled_orders' => $orders->where('status', 'cancelado')->count(),
                'sales' => round($ventas, 2),
                'average_ticket' => $pagados->count() ? round($ventas / $pagados->count(), 2) : 0,
            ],
            'sales_by_day' => array_values(array_map(function ($d) {
                $d['sales'] = round($d['sales'], 2);
                return $d;
            }, $porDia)),
            'top_products' => $topProductos,
            'visits' => [
                'unique_diners' => $comensalesUnicos,
                'total_visits' => $totalVisitas,
                'average_per_diner' => $comensalesUnicos ? round($totalVisitas / $comensalesUnicos, 2) : 0,
                'frequent_diners' => $frecuentes,
            ],
            'loyalty' => [
                'registered_diners' => $registrados,
                'participants' => $participantes,
                'participation_rate' => $registrados ? round($participantes * 100 / $registrados, 1) : 0,
                'xp_awarded' => (int) LoyaltyPoint::whereBetween('created_at', [$from, $to])->sum('points'),
                'badges_awarded' => UserBadge::whereBetween('obtained_at', [$from, $to])->count(),
                'active_streaks' => Streak::where('current_streak', '>', 0)
                    ->where('last_visit', '>=', now()->subDay()->toDateString())
                    ->count(),
            ],
        ]);
    }
}
