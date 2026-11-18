<?php

namespace Tests\Unit;

use App\Reglas\ReglasDelReporte;
use PHPUnit\Framework\TestCase;

/** Pruebas unitarias de los cálculos del reporte del administrador, sin base de datos. */
class ReglasDelReporteTest extends TestCase
{
    public function test_una_visita_es_un_dia_distinto_con_pedido_pagado(): void
    {
        $pagos = [
            [17, '2026-11-17'],
            [17, '2026-11-17'],   // segundo pedido el mismo día: sigue siendo una visita
            [17, '2026-11-18'],
            [18, '2026-11-18'],
        ];

        $this->assertSame([17 => 2, 18 => 1], ReglasDelReporte::visitasPorComensal($pagos));
        $this->assertSame([], ReglasDelReporte::visitasPorComensal([]));
    }

    public function test_ticket_promedio_es_ventas_entre_pedidos_pagados(): void
    {
        $this->assertSame(30.0, ReglasDelReporte::ticketPromedio(60, 2));
        $this->assertSame(14.33, ReglasDelReporte::ticketPromedio(43, 3));
        $this->assertSame(0.0, ReglasDelReporte::ticketPromedio(0, 0));   // sin pedidos no divide entre cero
    }
}
