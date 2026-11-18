<?php
/**
 * Prueba de carga y concurrencia del API (RNF-02 y RNF-03), para el Anexo H.
 *
 * Corre SOLO en el entorno de prueba separado (docker-compose.carga.yml, base
 * "pension_carga", puerto 8100). Ver carga/LEEME.md. Uso normal: sh carga/ejecutar.sh
 *
 *   php carga/prueba_carga.php            -> fases "normal" y "pico"
 *   php carga/prueba_carga.php normal     -> solo una fase
 *
 * Cada usuario virtual es un proceso aparte (carga/usuario_virtual.php), así las
 * solicitudes llegan realmente en paralelo al servidor (Nginx + PHP-FPM + PostgreSQL).
 */

use App\Models\LoyaltyPoint;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Candado: nunca contra la base de la pensión ni contra el portal.
$base = config('database.connections.' . config('database.default') . '.database');
if ($base !== 'pension_carga' || config('portal.sync_url')) {
    fwrite(STDERR, "Abortado: esta prueba solo corre en el entorno de prueba (base pension_carga, sin portal). Base actual: $base\n");
    exit(1);
}

const URL = 'http://nginx/api';
$CARPETA = __DIR__ . '/resultados';

/**
 * Escenarios, a partir de la pensión: unos 180-200 comensales al día con picos a las
 * 13:00 y 14:00, 10 mesas, 1 mesero, 2 en cocina y 1 en caja.
 *  - normal: el turno de almuerzo con el personal real y comensales mirando su app.
 *  - pico: tres veces más dispositivos, trabajando al doble de ritmo, para ver el límite.
 * Cada sesión del API admite 60 solicitudes por minuto (RouteServiceProvider): el ritmo
 * de cada dispositivo es el de una persona real, no un bucle sin pausas.
 */
$FASES = [
    'normal' => ['segundos' => 180, 'ritmo' => 1.0, 'roles' => ['mesero' => 2, 'cocina' => 2, 'caja' => 1, 'cliente' => 4]],
    'pico' => ['segundos' => 120, 'ritmo' => 0.5, 'roles' => ['mesero' => 6, 'cocina' => 4, 'caja' => 2, 'cliente' => 18]],
];
$elegidas = array_slice($argv, 1) ?: array_keys($FASES);

// ---------------------------------------------------------------- preparación
/** Usuarios de prueba (se crean una vez) y su token, como si ya hubieran iniciado sesión. */
function usuarioDePrueba(string $rol, int $n): User
{
    $usuario = User::firstOrCreate(
        ['email' => "$rol$n@carga.local"],
        ['name' => ucfirst($rol) . " $n", 'password' => 'carga123', 'must_change_password' => false]
    );
    if (!$usuario->roles()->where('name', $rol)->exists()) {
        $usuario->roles()->attach(Role::firstOrCreate(['name' => $rol]));
    }
    return $usuario;
}

$comensales = [];
for ($i = 1; $i <= 40; $i++) {
    $comensales[] = usuarioDePrueba('cliente', $i)->id;
}

/** Tiempos de inicio de sesión: POST /login (limitado a 10 intentos por minuto por IP). */
function medirLogins(array $correos): array
{
    $multi = curl_multi_init();
    $manejadores = [];
    foreach ($correos as $correo) {
        $c = curl_init(URL . '/login');
        curl_setopt_array($c, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['email' => $correo, 'password' => 'carga123']),
        ]);
        curl_multi_add_handle($multi, $c);
        $manejadores[] = $c;
    }
    do {
        curl_multi_exec($multi, $activos);
        curl_multi_select($multi);
    } while ($activos);
    $filas = [];
    foreach ($manejadores as $c) {
        $filas[] = ['e' => 'POST /login', 'ms' => round(curl_getinfo($c, CURLINFO_TOTAL_TIME) * 1000, 1), 'c' => curl_getinfo($c, CURLINFO_HTTP_CODE)];
        curl_multi_remove_handle($multi, $c);
    }
    curl_multi_close($multi);
    return $filas;
}

// ---------------------------------------------------------------- métricas
function percentil(array $valores, float $p): float
{
    sort($valores);
    $i = (int) ceil($p / 100 * count($valores)) - 1;
    return $valores[max(0, min($i, count($valores) - 1))];
}

function resumir(array $filas, float $segundos): array
{
    $porEndpoint = [];
    foreach ($filas as $f) {
        $porEndpoint[$f['e']][] = $f;
    }
    ksort($porEndpoint);
    $tabla = [];
    foreach ($porEndpoint as $endpoint => $lista) {
        $ms = array_column($lista, 'ms');
        $codigos = array_column($lista, 'c');
        $errores = count(array_filter($codigos, fn ($c) => $c === 0 || $c >= 500 || in_array($c, [401, 403, 404, 405, 429])));
        $negocio = count(array_filter($codigos, fn ($c) => $c === 422));
        $tabla[$endpoint] = [
            'solicitudes' => count($lista),
            'promedio_ms' => round(array_sum($ms) / count($ms), 1),
            'p95_ms' => percentil($ms, 95),
            'max_ms' => max($ms),
            'errores' => $errores,
            'rechazos_422' => $negocio,
        ];
    }
    $todos = array_column($filas, 'ms');
    $codigos = array_column($filas, 'c');
    $errores = count(array_filter($codigos, fn ($c) => $c === 0 || $c >= 500 || in_array($c, [401, 403, 404, 405, 429])));
    return [
        'por_endpoint' => $tabla,
        'total' => [
            'solicitudes' => count($filas),
            'solicitudes_por_segundo' => round(count($filas) / $segundos, 1),
            'promedio_ms' => round(array_sum($todos) / max(1, count($todos)), 1),
            'p95_ms' => $todos ? percentil($todos, 95) : 0,
            'max_ms' => $todos ? max($todos) : 0,
            'errores' => $errores,
            'tasa_de_errores_pct' => round($errores * 100 / max(1, count($filas)), 2),
            'rechazos_422' => count(array_filter($codigos, fn ($c) => $c === 422)),
        ],
    ];
}

function tablaDeTexto(array $resumen): string
{
    $t = sprintf("%-30s %8s %10s %9s %9s %8s %8s\n", 'Endpoint', 'Solic.', 'Prom. ms', 'p95 ms', 'Máx. ms', 'Errores', '422');
    $t .= str_repeat('-', 88) . "\n";
    foreach ($resumen['por_endpoint'] as $e => $r) {
        $t .= sprintf("%-30s %8d %10.1f %9.1f %9.1f %8d %8d\n", $e, $r['solicitudes'], $r['promedio_ms'], $r['p95_ms'], $r['max_ms'], $r['errores'], $r['rechazos_422']);
    }
    $x = $resumen['total'];
    $t .= str_repeat('-', 88) . "\n";
    $t .= sprintf("%-30s %8d %10.1f %9.1f %9.1f %8d %8d\n", 'TOTAL', $x['solicitudes'], $x['promedio_ms'], $x['p95_ms'], $x['max_ms'], $x['errores'], $x['rechazos_422']);
    $t .= sprintf("Solicitudes por segundo: %.1f | Tasa de errores: %.2f %%\n", $x['solicitudes_por_segundo'], $x['tasa_de_errores_pct']);
    return $t;
}

// ---------------------------------------------------------------- consistencia
function verificarConsistencia(): array
{
    $pagosDobles = DB::table('payments')->where('status', 'completado')
        ->select('order_id')->groupBy('order_id')->havingRaw('COUNT(*) > 1')->pluck('order_id');
    $pendientesDobles = DB::table('payments')->where('status', 'pendiente')
        ->select('order_id')->groupBy('order_id')->havingRaw('COUNT(*) > 1')->pluck('order_id');

    $totalesMal = DB::table('orders')
        ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
        ->groupBy('orders.id', 'orders.total')
        ->havingRaw('ABS(orders.total - COALESCE(SUM(order_items.subtotal), 0)) > 0.001')
        ->pluck('orders.id');

    $pagadosSinPago = Order::where('status', 'pagado')
        ->whereDoesntHave('payments', fn ($q) => $q->where('status', 'completado'))->pluck('id');
    $pagoDistintoAlTotal = DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')
        ->where('payments.status', 'completado')
        ->whereRaw('ABS(payments.amount - orders.total) > 0.001')->pluck('orders.id');

    // XP: exactamente una acreditación por pedido pagado y ninguna por los demás.
    $xpPorPedido = LoyaltyPoint::where('reason', 'like', 'Pedido #% pagado')->get()
        ->countBy(fn ($x) => (int) preg_replace('/\D/', '', $x->reason));
    $pagados = Order::where('status', 'pagado')->whereNotNull('user_id')->pluck('id');
    $xpDoble = $xpPorPedido->filter(fn ($n) => $n > 1)->keys();
    $pagadosSinXp = $pagados->diff($xpPorPedido->keys());
    $xpSinPagar = $xpPorPedido->keys()->diff($pagados);

    $problemas = [
        'pedidos_con_dos_pagos_completados' => $pagosDobles->count(),
        'pedidos_con_dos_pagos_por_confirmar' => $pendientesDobles->count(),
        'pedidos_con_total_distinto_a_sus_platos' => $totalesMal->count(),
        'pedidos_pagados_sin_pago' => $pagadosSinPago->count(),
        'pagos_con_monto_distinto_al_total' => $pagoDistintoAlTotal->count(),
        'pedidos_con_xp_acreditado_mas_de_una_vez' => $xpDoble->count(),
        'pedidos_pagados_sin_xp' => $pagadosSinXp->count(),
        'xp_de_pedidos_no_pagados' => $xpSinPagar->count(),
    ];
    return [
        'pedidos' => Order::count(),
        'pedidos_por_estado' => Order::select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status'),
        'pagos_completados' => Payment::where('status', 'completado')->count(),
        'problemas' => $problemas,
        'consistente' => array_sum($problemas) === 0,
    ];
}

// ---------------------------------------------------------------- ejecución
@mkdir($CARPETA, 0777, true);
$informe = [
    'fecha' => now()->toDateTimeString(),
    'servidor' => 'Nginx 1.27 + PHP-FPM 8.2 (pm.max_children = 5) + PostgreSQL 16, en Docker',
    'fases' => [],
];
$texto = "PRUEBA DE CARGA Y CONCURRENCIA — Pensión Doña Roberta (entorno de prueba, puerto 8100)\n"
    . "Fecha: {$informe['fecha']}\nServidor: {$informe['servidor']}\n\n";

foreach ($elegidas as $nombre) {
    $fase = $FASES[$nombre];
    $vus = array_sum($fase['roles']);
    echo "Fase '$nombre': $vus usuarios virtuales durante {$fase['segundos']} s...\n";

    // Usuarios del personal para esta fase, cada uno con su token (sesión iniciada).
    $procesos = [];
    $n = 0;
    foreach ($fase['roles'] as $rol => $cantidad) {
        for ($i = 1; $i <= $cantidad; $i++) {
            $usuario = $rol === 'cliente'
                ? User::findOrFail($comensales[($i - 1) % count($comensales)])
                : usuarioDePrueba($rol, $i);
            $salida = sys_get_temp_dir() . "/vu_{$nombre}_{$n}.jsonl";
            $parametros = base64_encode(json_encode([
                'base' => URL, 'rol' => $rol, 'segundos' => $fase['segundos'], 'salida' => $salida,
                'token' => $usuario->createToken('carga')->plainTextToken,
                'comensales' => $comensales, 'semilla' => 1000 + $n, 'ritmo' => $fase['ritmo'],
            ]));
            $procesos[] = [proc_open(['php', __DIR__ . '/usuario_virtual.php', $parametros], [], $tuberias), $salida];
            $n++;
        }
    }

    // Inicio de sesión: 8 a la vez (el API limita a 10 por minuto por IP; más serían 429).
    $logins = medirLogins(array_map(fn ($i) => "cliente$i@carga.local", range(1, 8)));

    foreach ($procesos as [$proceso]) {
        proc_close($proceso);
    }

    $filas = $logins;
    foreach ($procesos as [, $salida]) {
        foreach (file($salida, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $filas[] = json_decode($linea, true);
        }
        @unlink($salida);
    }

    $resumen = resumir($filas, $fase['segundos']);
    $informe['fases'][$nombre] = ['usuarios_virtuales' => $vus, 'roles' => $fase['roles'], 'segundos' => $fase['segundos']] + $resumen;

    $roles = implode(', ', array_map(fn ($r, $c) => "$c $r", array_keys($fase['roles']), $fase['roles']));
    $texto .= "=== Fase '$nombre': $vus usuarios virtuales simultáneos ($roles), {$fase['segundos']} s ===\n";
    $texto .= tablaDeTexto($resumen) . "\n";
    echo tablaDeTexto($resumen) . "\n";
}

$consistencia = verificarConsistencia();
$informe['consistencia'] = $consistencia;
$texto .= "=== Consistencia de los datos al terminar ===\n";
$texto .= "Pedidos: {$consistencia['pedidos']} | Pagos completados: {$consistencia['pagos_completados']}\n";
$texto .= 'Pedidos por estado: ' . json_encode($consistencia['pedidos_por_estado']) . "\n";
foreach ($consistencia['problemas'] as $verificacion => $cantidad) {
    $texto .= sprintf("  %-45s %d\n", str_replace('_', ' ', $verificacion), $cantidad);
}
$texto .= $consistencia['consistente'] ? "RESULTADO: datos consistentes.\n" : "RESULTADO: SE ENCONTRARON INCONSISTENCIAS.\n";

file_put_contents("$CARPETA/carga_resumen.txt", $texto);
file_put_contents("$CARPETA/carga_resumen.json", json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n" . substr($texto, strpos($texto, '=== Consistencia'));
echo "\nSalidas: carga/resultados/carga_resumen.txt y carga_resumen.json\n";
