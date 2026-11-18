<?php

namespace App\Reglas;

/**
 * Reglas de la fidelización como funciones puras (sin base de datos), para
 * probarlas de forma aislada. Las usan PaymentController, LoyaltyService y
 * ChallengeController.
 */
final class ReglasFidelizacion
{
    /** XP que gana el comensal al pagar: un punto por boliviano, mínimo 5. */
    public static function xpPorPago(float $monto): int
    {
        return max(5, (int) round($monto));
    }

    /**
     * Racha de días consecutivos con visita:
     * - mismo día que la última visita: no cambia;
     * - día siguiente: suma 1;
     * - primera visita o un salto de días: vuelve a 1.
     * Fechas en formato Y-m-d.
     */
    public static function siguienteRacha(?string $ultimaVisita, string $hoy, int $rachaActual): int
    {
        if ($ultimaVisita === null) {
            return 1;
        }
        $dias = (int) round((strtotime($hoy) - strtotime($ultimaVisita)) / 86400);
        return match (true) {
            $dias === 0 => $rachaActual,
            $dias === 1 => $rachaActual + 1,
            default => 1,
        };
    }

    /**
     * Cuánto avanza un reto con un pedido pagado, según su tipo:
     * visitas una vez por día, compras 1 por pedido, gastronómico los productos
     * que el comensal nunca había pagado, especial 0 (lo registra el administrador).
     */
    public static function avanceDelReto(string $tipo, ?string $ultimoDiaContado, string $hoy, int $productosNuevos): int
    {
        return match ($tipo) {
            'visitas' => $ultimoDiaContado === $hoy ? 0 : 1,
            'compras' => 1,
            'gastronomico' => $productosNuevos,
            default => 0,
        };
    }

    /** Porcentaje de avance hacia la meta, de 0 a 100. */
    public static function progreso(int $cuenta, int $meta): int
    {
        return min(100, intdiv(max(0, $cuenta) * 100, max(1, $meta)));
    }

    public static function retoCompletado(int $cuenta, int $meta): bool
    {
        return $cuenta >= max(1, $meta);
    }
}
