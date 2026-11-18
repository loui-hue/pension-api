#!/bin/sh
# Prueba de carga y concurrencia completa, en el ENTORNO DE PRUEBA SEPARADO
# (proyecto "pension-carga", base pension_carga, puerto 8100). No toca la base de
# la pensión (puerto 8000), ni el portal, ni el VPS.
#
# Uso, desde la carpeta pension-api:   sh carga/ejecutar.sh
# Salidas en carga/resultados/:
#   concurrencia.txt        pruebas de cobros simultáneos (PHPUnit contra PostgreSQL real)
#   carga_resumen.txt/json  tiempos por endpoint, solicitudes/s, errores y consistencia
#   docker_stats.csv        CPU y memoria de los contenedores durante la carga
set -e
cd "$(dirname "$0")/.."
C="docker compose -p pension-carga -f docker-compose.carga.yml"
R=carga/resultados
mkdir -p "$R"

echo "1) Levantando el entorno de prueba (base nueva y vacía)..."
$C down -v >/dev/null 2>&1 || true
$C up -d --build
until curl -s http://localhost:8100/api/ping >/dev/null; do sleep 2; done
$C exec -T api php artisan db:seed --force >/dev/null

echo "2) Pruebas de concurrencia (cobros simultáneos)..."
$C exec -T api php vendor/bin/phpunit -c phpunit.concurrencia.xml | tee "$R/concurrencia.txt"

echo "3) Prueba de carga (fases normal y pico, unos 5 minutos)..."
echo "hora,contenedor,cpu,memoria" > "$R/docker_stats.csv"
( while true; do
    docker stats --no-stream --format "{{.Name}},{{.CPUPerc}},{{.MemUsage}}" \
      pension-carga-api-1 pension-carga-db-1 pension-carga-nginx-1 2>/dev/null \
      | sed "s/^/$(date +%H:%M:%S),/" >> "$R/docker_stats.csv"
    sleep 5
  done ) &
MUESTREO=$!
$C exec -T api php carga/prueba_carga.php || true
kill $MUESTREO 2>/dev/null || true

echo "4) CPU y memoria máximas durante la carga:"
for n in api db nginx; do
  grep "pension-carga-$n-1" "$R/docker_stats.csv" \
    | awk -F, -v n=$n '{gsub("%","",$3); if ($3+0 > m) m = $3+0; mem=$4} END {printf "   %-6s CPU máx. %.1f %%  | memoria (última muestra) %s\n", n, m, mem}'
done | tee "$R/docker_stats_resumen.txt"

echo "Listo. Resultados en $R/. Para borrar el entorno: $C down -v"
