<?php

namespace App\Reglas;

/** Cálculos del reporte del administrador como funciones puras (sin base de datos). */
final class ReglasDelReporte
{
    /**
     * Visitas por comensal: una visita = un día distinto con pedido pagado.
     *
     * @param  iterable<array{0:int,1:string}>  $pagos  pares [id del comensal, fecha Y-m-d]
     * @return array<int,int>  id del comensal => cantidad de visitas
     */
    public static function visitasPorComensal(iterable $pagos): array
    {
        $dias = [];
        foreach ($pagos as [$comensal, $fecha]) {
            $dias[$comensal][$fecha] = true;
        }
        return array_map('count', $dias);
    }

    /** Ticket promedio: ventas entre pedidos pagados (0 si no hubo pedidos). */
    public static function ticketPromedio(float $ventas, int $pedidosPagados): float
    {
        return $pedidosPagados > 0 ? round($ventas / $pedidosPagados, 2) : 0.0;
    }
}
