<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Streak;
use App\Models\User;
use App\Models\UserBadge;
use App\Reglas\ReglasDelReporte;
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
            ->whereNull('order_items.courtesy_badge_id')   // los más vendidos: sin las cortesías
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
        $visitas = collect(ReglasDelReporte::visitasPorComensal(
            $pagados->whereIn('user_id', $clienteIds)
                ->map(fn ($o) => [$o->user_id, $o->created_at->toDateString()])
        ));

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

        // Ventas por método de pago (control de caja): pagos completados del periodo,
        // por la fecha en que se cobró (confirmed_at; si faltara, created_at del pago).
        // Los pendientes y rechazados no suman.
        $fechaDelPago = 'COALESCE(payments.confirmed_at, payments.created_at)';
        $cobrado = Payment::query()
            ->where('status', 'completado')
            ->whereRaw("$fechaDelPago BETWEEN ? AND ?", [$from, $to])
            ->groupBy('payment_method_id')
            ->selectRaw('payment_method_id, COUNT(*) as payments, SUM(amount) as amount')
            ->get()
            ->keyBy('payment_method_id');
        $porMetodo = PaymentMethod::orderBy('id')->get()->map(function ($metodo) use ($cobrado) {
            $suma = $cobrado->get($metodo->id);
            return [
                'payment_method_id' => $metodo->id,
                'name' => $metodo->name,
                'payments' => (int) ($suma->payments ?? 0),
                'amount' => round((float) ($suma->amount ?? 0), 2),
            ];
        })->values();
        $porConfirmar = Payment::where('status', 'pendiente');

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
                'average_ticket' => ReglasDelReporte::ticketPromedio($ventas, $pagados->count()),
            ],
            'sales_by_day' => array_values(array_map(function ($d) {
                $d['sales'] = round($d['sales'], 2);
                return $d;
            }, $porDia)),
            'top_products' => $topProductos,
            'payments_by_method' => [
                'methods' => $porMetodo,
                'total_payments' => $porMetodo->sum('payments'),
                'total_amount' => round($porMetodo->sum('amount'), 2),
                // Pagos por QR o transferencia que caja todavía no confirma (en este momento).
                'pending_confirmation' => [
                    'payments' => (clone $porConfirmar)->count(),
                    'amount' => round((float) (clone $porConfirmar)->sum('amount'), 2),
                ],
            ],
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
                // Refrescos de cortesía de Cliente Frecuente en pedidos no cancelados del período.
                'courtesies_given' => OrderItem::whereNotNull('courtesy_badge_id')
                    ->whereHas('order', fn ($q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelado'))
                    ->count(),
                'active_streaks' => Streak::where('current_streak', '>', 0)
                    ->where('last_visit', '>=', now()->subDay()->toDateString())
                    ->count(),
            ],
        ]);
    }
}
