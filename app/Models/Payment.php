<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    /** Minutos que tiene caja para verificar un pago por QR o transferencia. */
    public const MINUTOS_CONFIRMACION = 10;

    protected $fillable = [
        'order_id',
        'payment_method_id',
        'amount',
        'status',
        'reference',
        'expires_at',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    // Se calcula en el servidor para que la cuenta regresiva no dependa del reloj del celular.
    protected $appends = ['seconds_left'];

    public function getSecondsLeftAttribute(): ?int
    {
        if ($this->status !== 'pendiente' || !$this->expires_at) {
            return null;
        }
        return max(0, now()->diffInSeconds($this->expires_at, false));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
