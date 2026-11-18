<?php

namespace App\Reglas;

use DateTimeInterface;

/** Reglas de los pagos como funciones puras (sin base de datos). */
final class ReglasDePago
{
    /**
     * Segundos que le quedan a caja para verificar un pago por QR o transferencia:
     * null si el pago no está pendiente o no tiene plazo; 0 si ya venció.
     */
    public static function segundosRestantes(string $estado, ?DateTimeInterface $vence, DateTimeInterface $ahora): ?int
    {
        if ($estado !== 'pendiente' || $vence === null) {
            return null;
        }
        return max(0, $vence->getTimestamp() - $ahora->getTimestamp());
    }
}
