<?php

namespace Tests\Unit;

use App\Reglas\ReglasDePago;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Prueba unitaria del plazo de verificación de un pago por QR o transferencia
 * (seconds_left del modelo Payment), sin base de datos.
 */
class ReglasDePagoTest extends TestCase
{
    private DateTimeImmutable $ahora;

    protected function setUp(): void
    {
        $this->ahora = new DateTimeImmutable('2026-11-18 13:00:00');
    }

    public function test_un_pago_pendiente_con_plazo_devuelve_los_segundos_que_faltan(): void
    {
        $vence = $this->ahora->modify('+10 minutes');
        $this->assertSame(600, ReglasDePago::segundosRestantes('pendiente', $vence, $this->ahora));
        $this->assertSame(90, ReglasDePago::segundosRestantes('pendiente', $this->ahora->modify('+90 seconds'), $this->ahora));
    }

    public function test_un_pago_vencido_devuelve_cero(): void
    {
        $this->assertSame(0, ReglasDePago::segundosRestantes('pendiente', $this->ahora, $this->ahora));
        $this->assertSame(0, ReglasDePago::segundosRestantes('pendiente', $this->ahora->modify('-5 minutes'), $this->ahora));
    }

    public function test_si_no_esta_pendiente_o_no_tiene_plazo_devuelve_null(): void
    {
        $vence = $this->ahora->modify('+10 minutes');
        $this->assertNull(ReglasDePago::segundosRestantes('completado', $vence, $this->ahora));
        $this->assertNull(ReglasDePago::segundosRestantes('rechazado', $vence, $this->ahora));
        $this->assertNull(ReglasDePago::segundosRestantes('pendiente', null, $this->ahora));
    }
}
