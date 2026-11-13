<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Order;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    public function __construct(private LoyaltyService $loyaltyService)
    {
    }

    public function index(Request $request)
    {
        // ?status=pendiente lista los pagos por QR/transferencia que caja debe verificar.
        $payments = Payment::with(['order.user', 'order.table', 'paymentMethod', 'confirmedBy'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($payments);
    }

    public function store(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'amount' => 'required|numeric|min:0',
            'reference' => 'nullable|string',
        ]);

        $order = Order::findOrFail($request->order_id);
        if (in_array($order->status, ['pagado', 'cancelado'])) {
            return response()->json([
                'message' => "El pedido #{$order->id} ya está {$order->status}",
            ], 422);
        }
        if ($order->payments()->where('status', 'pendiente')->exists()) {
            return response()->json([
                'message' => "El pedido #{$order->id} ya tiene un pago por confirmar",
            ], 422);
        }

        $method = PaymentMethod::findOrFail($request->payment_method_id);

        // QR y transferencia: el pago queda pendiente hasta que caja verifique el depósito.
        if ($method->requires_confirmation) {
            $payment = Payment::create([
                'order_id' => $order->id,
                'payment_method_id' => $method->id,
                'amount' => $request->amount,
                'status' => 'pendiente',
                'reference' => $request->reference,
                'expires_at' => now()->addMinutes(Payment::MINUTOS_CONFIRMACION),
            ]);

            return response()->json($payment->load(['order', 'paymentMethod']), 201);
        }

        // Efectivo y tarjeta se confirman en el acto.
        $payment = DB::transaction(function () use ($request, $order, $method) {
            $payment = Payment::create([
                'order_id' => $order->id,
                'payment_method_id' => $method->id,
                'amount' => $request->amount,
                'status' => 'completado',
                'reference' => $request->reference,
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);
            $this->completarPedido($payment);
            return $payment;
        });

        return response()->json($payment->load(['order', 'paymentMethod']), 201);
    }

    /**
     * Caja verificó el depósito en la cuenta de la pensión. Se permite aunque
     * el plazo haya vencido: el aviso del banco puede llegar tarde.
     */
    public function confirm(Request $request, $id)
    {
        if ($error = $this->soloCaja($request)) {
            return $error;
        }
        $request->validate(['reference' => 'nullable|string|max:100']);

        $payment = Payment::findOrFail($id);
        if ($payment->status !== 'pendiente') {
            return response()->json(['message' => "El pago ya está {$payment->status}"], 422);
        }

        DB::transaction(function () use ($request, $payment) {
            $payment->update([
                'status' => 'completado',
                'reference' => $request->reference ?: $payment->reference,
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);
            $this->completarPedido($payment);
        });

        return response()->json($payment->fresh()->load(['order.user', 'paymentMethod', 'confirmedBy']));
    }

    /** El depósito no llegó: el pedido vuelve a "por cobrar" para cobrarse de otra forma. */
    public function reject(Request $request, $id)
    {
        if ($error = $this->soloCaja($request)) {
            return $error;
        }

        $payment = Payment::findOrFail($id);
        if ($payment->status !== 'pendiente') {
            return response()->json(['message' => "El pago ya está {$payment->status}"], 422);
        }

        $payment->update([
            'status' => 'rechazado',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
        ]);

        return response()->json($payment->load(['order.user', 'paymentMethod', 'confirmedBy']));
    }

    public function show($id)
    {
        $payment = Payment::with(['order', 'paymentMethod', 'confirmedBy'])->findOrFail($id);
        return response()->json($payment);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'reference' => 'nullable|string',
        ]);

        $payment = Payment::findOrFail($id);
        $payment->update($validated);

        return response()->json($payment->load(['order', 'paymentMethod']));
    }

    public function destroy($id)
    {
        $payment = Payment::findOrFail($id);
        $payment->delete();
        return response()->json(['message' => 'Pago eliminado']);
    }

    public function methods()
    {
        $methods = PaymentMethod::where('active', true)->get();
        return response()->json($methods);
    }

    /** El administrador carga los datos de cobro de la pensión y la imagen del QR fijo. */
    public function updateMethod(Request $request, $id)
    {
        if (!$request->user()->hasRole('administrador')) {
            return response()->json(['message' => 'Solo el administrador puede configurar los métodos de pago'], 403);
        }

        $validated = $request->validate([
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:50',
            'account_holder' => 'nullable|string|max:100',
            'qr_image' => 'nullable|image|max:4096',
        ]);

        $method = PaymentMethod::findOrFail($id);
        unset($validated['qr_image']);

        if ($request->hasFile('qr_image')) {
            if ($method->qr_image_path) {
                Storage::delete($method->qr_image_path);
            }
            $validated['qr_image_path'] = $request->file('qr_image')->store('qr');
        }

        $method->update($validated);

        return response()->json($method->fresh());
    }

    public function qrImage($id)
    {
        $method = PaymentMethod::findOrFail($id);
        if (!$method->qr_image_path || !Storage::exists($method->qr_image_path)) {
            return response()->json(['message' => 'Este método no tiene QR cargado'], 404);
        }
        return Storage::response($method->qr_image_path);
    }

    /** Pedido pagado: libera la mesa y acredita XP y racha al comensal. */
    private function completarPedido(Payment $payment): void
    {
        $order = $payment->order;
        $order->update(['status' => 'pagado']);
        $order->table()->update(['status' => 'disponible']);

        $comensal = $order->user;
        if ($comensal) {
            $xpGanado = max(5, (int) round($payment->amount));
            $this->loyaltyService->awardXp($comensal, $xpGanado, "Pedido #{$order->id} pagado");
            $this->loyaltyService->registerVisit($comensal);
        }
    }

    private function soloCaja(Request $request)
    {
        $usuario = $request->user();
        if (!$usuario->hasRole('caja') && !$usuario->hasRole('administrador')) {
            return response()->json(['message' => 'Solo caja o el administrador pueden confirmar pagos'], 403);
        }
        return null;
    }
}
