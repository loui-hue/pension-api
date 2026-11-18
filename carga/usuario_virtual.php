<?php
/**
 * Un "usuario virtual" de la prueba de carga: un dispositivo de la pensión que usa
 * el API como lo hace la app (ver carga/LEEME.md). Lo lanza carga/prueba_carga.php,
 * uno por proceso, para que las solicitudes ocurran realmente en paralelo.
 *
 * Escribe una línea JSON por solicitud en el archivo de salida:
 *   {"e":"POST /orders","ms":35.2,"c":201}
 */

[$_, $parametros] = $argv;
$p = json_decode(base64_decode($parametros), true);

$base = $p['base'];
$token = $p['token'];
$rol = $p['rol'];
$hasta = microtime(true) + $p['segundos'];
$comensales = $p['comensales'];
$salida = fopen($p['salida'], 'w');
mt_srand($p['semilla']);
// Ritmo de trabajo: 1 = una persona atendiendo con normalidad; 0.5 = el doble de rápido (pico).
$ritmo = $p['ritmo'] ?? 1.0;

/** Una solicitud HTTP medida. Devuelve [código, cuerpo decodificado]. */
function llamar(string $metodo, string $ruta, string $etiqueta, ?array $cuerpo = null): array
{
    global $base, $token, $salida;
    $c = curl_init($base . $ruta);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_filter([
            'Accept: application/json',
            'Content-Type: application/json',
            $token ? "Authorization: Bearer $token" : null,
        ]),
    ]);
    if ($cuerpo !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($cuerpo));
    }
    $inicio = microtime(true);
    $respuesta = curl_exec($c);
    $ms = (microtime(true) - $inicio) * 1000;
    $codigo = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);   // 0 = sin respuesta (error de red o tiempo agotado)
    curl_close($c);

    fwrite($salida, json_encode(['e' => $etiqueta, 'ms' => round($ms, 1), 'c' => $codigo, 't' => round($inicio, 3)]) . "\n");
    return [$codigo, $respuesta === false ? null : json_decode($respuesta, true)];
}

/** Pausa del usuario entre acciones (segundos, con decimales). */
function pensar(float $min, float $max): void
{
    global $ritmo;
    $min *= $ritmo;
    $max *= $ritmo;
    usleep((int) (($min + mt_rand() / mt_getrandmax() * ($max - $min)) * 1_000_000));
}

$ultimaConsulta = 0.0;
$pedidos = [];

/** La app de cada dispositivo consulta los pedidos cada 5 s (ConsultaPeriodica). */
function consultarPedidosSiToca(): void
{
    global $ultimaConsulta, $pedidos, $rol;
    if (microtime(true) - $ultimaConsulta < 5) {
        return;
    }
    $ultimaConsulta = microtime(true);
    if ($rol === 'cliente') {
        llamar('GET', '/orders/my-orders', 'GET /orders/my-orders');
        return;
    }
    [$codigo, $lista] = llamar('GET', '/orders', 'GET /orders');
    $pedidos = $codigo === 200 ? lista($lista) : [];
}

/** Solo acepta una lista (la respuesta de error del API es un objeto con "message"). */
function lista($respuesta): array
{
    return is_array($respuesta) && array_is_list($respuesta) ? $respuesta : [];
}

function conEstado(string $estado): array
{
    global $pedidos;
    return array_values(array_filter($pedidos, fn ($o) => ($o['status'] ?? '') === $estado));
}

$metodos = null;

while (microtime(true) < $hasta) {
    consultarPedidosSiToca();

    switch ($rol) {
        case 'mesero':
            // Registrar pedido: la pantalla carga la carta y las mesas, y se envía el pedido.
            [, $carta] = llamar('GET', '/products', 'GET /products');
            [, $mesas] = llamar('GET', '/tables', 'GET /tables');
            $carta = lista($carta);
            $mesas = lista($mesas);
            if ($carta && $mesas) {
                $platos = [];
                foreach ((array) array_rand($carta, min(count($carta), mt_rand(1, 3))) as $i) {
                    $platos[] = ['product_id' => $carta[$i]['id'], 'quantity' => mt_rand(1, 2)];
                }
                [$codigo, $pedido] = llamar('POST', '/orders', 'POST /orders', [
                    'table_id' => $mesas[array_rand($mesas)]['id'],
                    'user_id' => $comensales[array_rand($comensales)],
                    'items' => $platos,
                ]);
                if ($codigo === 201 && isset($pedido['id'])) {
                    pensar(1, 3);
                    llamar('PATCH', "/orders/{$pedido['id']}/status", 'PATCH /orders/{id}/status', ['status' => 'en_preparacion']);
                }
            }
            // Entregar a la mesa lo que cocina marcó como listo.
            foreach (array_slice(conEstado('listo'), 0, 2) as $o) {
                pensar(1, 2);
                llamar('PATCH', "/orders/{$o['id']}/status", 'PATCH /orders/{id}/status', ['status' => 'entregado']);
            }
            // Hasta el siguiente pedido: atender la mesa, tomar la orden (15 a 30 s).
            pensar(15, 30);
            break;

        case 'cocina':
            foreach (array_slice(conEstado('en_preparacion'), 0, 2) as $o) {
                pensar(3, 6);
                llamar('PATCH', "/orders/{$o['id']}/status", 'PATCH /orders/{id}/status', ['status' => 'listo']);
            }
            pensar(1, 2);
            break;

        case 'caja':
            if ($metodos === null) {
                [, $lista] = llamar('GET', '/payment-methods', 'GET /payment-methods');
                $metodos = collect_metodos(lista($lista));
            }
            foreach (array_slice(conEstado('entregado'), 0, 1) as $o) {
                // 7 de cada 10 en efectivo; el resto por QR, que caja confirma después.
                $porQr = mt_rand(1, 10) > 7 && isset($metodos['QR']);
                [$codigo, $pago] = llamar('POST', '/payments', 'POST /payments', [
                    'order_id' => $o['id'],
                    'payment_method_id' => $porQr ? $metodos['QR'] : $metodos['Efectivo'],
                    'amount' => (float) $o['total'],
                ]);
                if ($porQr && $codigo === 201 && isset($pago['id'])) {
                    pensar(2, 4);
                    llamar('PATCH', "/payments/{$pago['id']}/confirm", 'PATCH /payments/{id}/confirm', []);
                }
            }
            // Atender al siguiente comensal en caja.
            pensar(4, 8);
            break;

        case 'cliente':
            // Mira la carta y su avance de vez en cuando; "Mis pedidos" se consulta cada 5 s.
            if (mt_rand(1, 6) === 1) {
                llamar('GET', '/products', 'GET /products');
                llamar('GET', '/loyalty', 'GET /loyalty');
            }
            pensar(4, 6);
            break;
    }
}

function collect_metodos(array $lista): array
{
    $porNombre = [];
    foreach ($lista as $m) {
        $porNombre[$m['name']] = $m['id'];
    }
    return $porNombre;
}

fclose($salida);
