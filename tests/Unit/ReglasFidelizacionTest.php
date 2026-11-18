<?php

namespace Tests\Unit;

use App\Reglas\ReglasFidelizacion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas unitarias de las reglas de fidelización: funciones puras, sin base de
 * datos ni framework (aisladas, rápidas y deterministas).
 */
class ReglasFidelizacionTest extends TestCase
{
    public static function montos(): array
    {
        return [
            'pedido de Bs 29 da 29 XP' => [29.0, 29],
            'se redondea al entero más cercano' => [14.5, 15],
            'hacia abajo si es menor a .5' => [31.4, 31],
            'un pedido barato da el mínimo de 5' => [3.0, 5],
            'monto cero da el mínimo de 5' => [0.0, 5],
        ];
    }

    #[DataProvider('montos')]
    public function test_xp_por_pago_es_el_monto_redondeado_con_minimo_5(float $monto, int $xp): void
    {
        $this->assertSame($xp, ReglasFidelizacion::xpPorPago($monto));
    }

    public function test_la_racha_no_cambia_el_mismo_dia(): void
    {
        $this->assertSame(3, ReglasFidelizacion::siguienteRacha('2026-11-17', '2026-11-17', 3));
    }

    public function test_la_racha_suma_uno_al_dia_siguiente(): void
    {
        $this->assertSame(4, ReglasFidelizacion::siguienteRacha('2026-11-17', '2026-11-18', 3));
        // También entre meses.
        $this->assertSame(2, ReglasFidelizacion::siguienteRacha('2026-10-31', '2026-11-01', 1));
    }

    public function test_la_racha_vuelve_a_uno_tras_un_salto_o_en_la_primera_visita(): void
    {
        $this->assertSame(1, ReglasFidelizacion::siguienteRacha('2026-11-15', '2026-11-18', 7));
        $this->assertSame(1, ReglasFidelizacion::siguienteRacha(null, '2026-11-18', 0));
    }

    public function test_avance_de_visitas_cuenta_una_vez_por_dia(): void
    {
        $this->assertSame(1, ReglasFidelizacion::avanceDelReto('visitas', null, '2026-11-18', 0));
        $this->assertSame(1, ReglasFidelizacion::avanceDelReto('visitas', '2026-11-17', '2026-11-18', 0));
        $this->assertSame(0, ReglasFidelizacion::avanceDelReto('visitas', '2026-11-18', '2026-11-18', 0));
    }

    public function test_avance_de_compras_gastronomico_y_especial(): void
    {
        $this->assertSame(1, ReglasFidelizacion::avanceDelReto('compras', '2026-11-18', '2026-11-18', 3));
        $this->assertSame(3, ReglasFidelizacion::avanceDelReto('gastronomico', null, '2026-11-18', 3));
        $this->assertSame(0, ReglasFidelizacion::avanceDelReto('gastronomico', null, '2026-11-18', 0));
        $this->assertSame(0, ReglasFidelizacion::avanceDelReto('especial', null, '2026-11-18', 5));
    }

    public function test_progreso_es_el_porcentaje_hacia_la_meta_con_tope_100(): void
    {
        $this->assertSame(0, ReglasFidelizacion::progreso(0, 3));
        $this->assertSame(33, ReglasFidelizacion::progreso(1, 3));
        $this->assertSame(66, ReglasFidelizacion::progreso(2, 3));
        $this->assertSame(100, ReglasFidelizacion::progreso(3, 3));
        $this->assertSame(100, ReglasFidelizacion::progreso(7, 5));   // nunca pasa de 100
        $this->assertSame(100, ReglasFidelizacion::progreso(1, 0));   // meta inválida se trata como 1
    }

    public function test_el_reto_se_completa_al_llegar_a_la_meta(): void
    {
        $this->assertFalse(ReglasFidelizacion::retoCompletado(2, 3));
        $this->assertTrue(ReglasFidelizacion::retoCompletado(3, 3));
        $this->assertTrue(ReglasFidelizacion::retoCompletado(4, 3));
    }
}
