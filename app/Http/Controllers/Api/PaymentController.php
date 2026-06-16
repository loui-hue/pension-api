<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Order;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index()
    {
        $payments = Payment::with(['order', 'paymentMethod'])
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

        $payment = Payment::create([
            'order_id' => $request->order_id,
            'payment_method_id' => $request->payment_method_id,
            'amount' => $request->amount,
            'status' => 'completado',
            'reference' => $request->reference,
        ]);

        Order::findOrFail($request->order_id)
            ->update(['status' => 'pagado']);

        return response()->json($payment->load(['order', 'paymentMethod']), 201);
    }

    public function show($id)
    {
        $payment = Payment::with(['order', 'paymentMethod'])->findOrFail($id);
        return response()->json($payment);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'sometimes|required|in:pendiente,completado,fallido,reembolsado',
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
}