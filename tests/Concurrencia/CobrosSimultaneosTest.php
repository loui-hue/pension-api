<?php

namespace Tests\Concurrencia;

use App\Models\LoyaltyPoint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Condición de carrera en los cobros (RNF-03: varios dispositivos a la vez sin
 * datos inconsistentes). Se envían varias solicitudes AL MISMO TIEMPO al servidor
 * real (Nginx + PHP-FPM + PostgreSQL del entorno de prueba separado) y se revisa
 * la base: un pedido no puede quedar con dos pagos ni dar el XP dos veces.
 *
 * No usa RefreshDatabase: el servidor es otro proceso y debe ver los datos.
 * Cada prueba crea sus propios registros con nombres únicos.
 *
 * Ejecutar (ver carga/LEEME.md):
 *   docker compose -p pension-carga -f docker-compose.carga.yml exec api \
 *     php vendor/bin/phpunit -c phpunit.concurrencia.xml
 */
class CobrosSimultaneosTest extends TestCase
{
    private const SOLICITUDES = 10;

    private string $url;
    private string $tokenCaja;
    private User $comensal;
    private Table $mesa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->url = rtrim(env('CONCURRENCIA_URL', 'http://nginx/api'), '/');

        // Estas pruebas sí hacen peticiones HTTP reales, pero solo al servidor del
        // entorno de prueba separado (nunca a la pensión ni al portal).
        $destino = parse_url($this->url);
        $permitido = ($destino['host'] ?? '') === 'nginx'
            || (in_array($destino['host'] ?? '', ['localhost', '127.0.0.1'], true) && ($destino['port'] ?? 80) === 8100);
        if (!$permitido) {
            throw new \RuntimeException("CONCURRENCIA_URL debe apuntar al entorno de prueba (http://nginx/api o puerto 8100): {$this->url}");
        }

        $marca = Str::lower(Str::random(8));
        $caja = User::create(['name' => "Caja $marca", 'email' => "caja-$marca@carga.local", 'password' => 'secreto123']);
        $caja->roles()->attach(Role::firstOrCreate(['name' => 'caja']));
        $this->tokenCaja = $caja->createToken('concurrencia')->plainTextToken;

        $this->comensal = User::create(['name' => "Comensal $marca", 'email' => "comensal-$marca@carga.local", 'password' => 'secreto123']);
        $this->comensal->roles()->attach(Role::firstOrCreate(['name' => 'cliente']));
        $this->mesa = Table::create(['number' => random_int(1000, 999999), 'capacity' => 4, 'status' => 'ocupada']);
    }

    private function pedidoEntregado(float $total = 29): Order
    {
        $pedido = Order::create([
            'user_id' => $this->comensal->id, 'table_id' => $this->mesa->id,
            'status' => 'entregado', 'total' => $total,
        ]);
        // Un plato por el total, para que el pedido sea consistente (total = suma de platos).
        OrderItem::create([
            'order_id' => $pedido->id, 'product_id' => Product::firstOrFail()->id,
            'quantity' => 1, 'unit_price' => $total, 'subtotal' => $total,
        ]);
        return $pedido;
    }

    private function metodo(string $nombre, bool $conConfirmacion): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(['name' => $nombre], ['requires_confirmation' => $conConfirmacion, 'active' => true]);
    }

    /**
     * Envía las solicitudes en paralelo (todas salen a la vez, sin esperar respuesta)
     * y devuelve los códigos HTTP. $solicitud recibe un cliente y devuelve una promesa.
     */
    private function enParalelo(callable $solicitud): array
    {
        $cliente = new Client([
            'http_errors' => false,
            'timeout' => 30,
            'headers' => ['Authorization' => "Bearer {$this->tokenCaja}", 'Accept' => 'application/json'],
        ]);
        $promesas = [];
        for ($i = 0; $i < self::SOLICITUDES; $i++) {
            $promesas[] = $solicitud($cliente);
        }
        return array_map(
            fn ($r) => $r['state'] === 'fulfilled' ? $r['value']->getStatusCode() : 0,
            Utils::settle($promesas)->wait()
        );
    }

    private function xpDelPedido(Order $pedido): int
    {
        return LoyaltyPoint::where('reason', "Pedido #{$pedido->id} pagado")->count();
    }

    private function informar(string $caso, array $codigos, int $pagos, int $xp): void
    {
        fwrite(STDOUT, sprintf(
            "\n[%s] %d solicitudes simultáneas -> códigos %s | pagos completados: %d | acreditaciones de XP: %d\n",
            $caso, count($codigos), json_encode(array_count_values($codigos)), $pagos, $xp
        ));
    }

    public function test_dos_o_mas_cobros_simultaneos_del_mismo_pedido_registran_un_solo_pago(): void
    {
        $pedido = $this->pedidoEntregado(29);
        $efectivo = $this->metodo('Efectivo', false);

        $codigos = $this->enParalelo(fn ($http) => $http->postAsync("{$this->url}/payments", ["json" => ['order_id' => $pedido->id, 'payment_method_id' => $efectivo->id, 'amount' => 29,]]));

        $pagos = Payment::where('order_id', $pedido->id)->where('status', 'completado')->count();
        $xp = $this->xpDelPedido($pedido);
        $this->informar('cobro en efectivo', $codigos, $pagos, $xp);

        $this->assertSame(1, $pagos, 'El pedido quedó con más de un pago completado');
        $this->assertSame(1, $xp, 'El XP del pedido se acreditó más de una vez');
        $this->assertSame('pagado', $pedido->fresh()->status);
        $this->assertSame(1, count(array_filter($codigos, fn ($c) => $c === 201)));
    }

    public function test_dos_o_mas_pagos_qr_simultaneos_dejan_un_solo_pago_por_confirmar(): void
    {
        $pedido = $this->pedidoEntregado(31);
        $qr = $this->metodo('QR', true);

        $codigos = $this->enParalelo(fn ($http) => $http->postAsync("{$this->url}/payments", ["json" => ['order_id' => $pedido->id, 'payment_method_id' => $qr->id, 'amount' => 31,]]));

        $pendientes = Payment::where('order_id', $pedido->id)->where('status', 'pendiente')->count();
        $this->informar('pago por QR', $codigos, $pendientes, $this->xpDelPedido($pedido));

        $this->assertSame(1, $pendientes, 'El pedido quedó con más de un pago QR por confirmar');
    }

    public function test_dos_o_mas_confirmaciones_simultaneas_del_mismo_pago_qr_acreditan_una_vez(): void
    {
        $pedido = $this->pedidoEntregado(31);
        $pago = Payment::create([
            'order_id' => $pedido->id, 'payment_method_id' => $this->metodo('QR', true)->id,
            'amount' => 31, 'status' => 'pendiente', 'expires_at' => now()->addMinutes(10),
        ]);

        $codigos = $this->enParalelo(fn ($http) => $http->patchAsync("{$this->url}/payments/{$pago->id}/confirm"));

        $xp = $this->xpDelPedido($pedido);
        $this->informar('confirmación de QR', $codigos, Payment::where('order_id', $pedido->id)->where('status', 'completado')->count(), $xp);

        $this->assertSame(1, $xp, 'La confirmación acreditó el XP más de una vez');
        $this->assertSame('completado', $pago->fresh()->status);
        $this->assertSame(1, count(array_filter($codigos, fn ($c) => $c === 200)));
    }

    public function test_cobrar_y_cancelar_a_la_vez_deja_el_pedido_consistente(): void
    {
        $pedido = $this->pedidoEntregado(29);
        $efectivo = $this->metodo('Efectivo', false);

        $i = 0;
        $codigos = $this->enParalelo(function ($http) use (&$i, $pedido, $efectivo) {
            return $i++ % 2 === 0
                ? $http->postAsync("{$this->url}/payments", ['json' => ['order_id' => $pedido->id, 'payment_method_id' => $efectivo->id, 'amount' => 29]])
                : $http->patchAsync("{$this->url}/orders/{$pedido->id}/status", ['json' => ['status' => 'cancelado']]);
        });

        $estado = $pedido->fresh()->status;
        $pagos = Payment::where('order_id', $pedido->id)->where('status', 'completado')->count();
        $this->informar("cobrar y cancelar (estado final: $estado)", $codigos, $pagos, $this->xpDelPedido($pedido));

        // Pagado con exactamente un pago, o cancelado sin ningún pago: nunca "cancelado con cobro".
        $this->assertTrue(
            ($estado === 'pagado' && $pagos === 1) || ($estado === 'cancelado' && $pagos === 0),
            "Estado inconsistente: pedido $estado con $pagos pagos completados"
        );
        $this->assertLessThanOrEqual(1, $this->xpDelPedido($pedido));
    }
}
