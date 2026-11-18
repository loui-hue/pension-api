# Pruebas de carga y concurrencia (RNF-02, RNF-03 — Anexo H)

Todo corre en un **entorno de prueba separado**: el proyecto de Docker `pension-carga`
(`docker-compose.carga.yml`), con su propia base `pension_carga` y el puerto **8100**.
No toca la base de la pensión (puerto 8000), ni el portal en la nube, ni el VPS.
Los dos candados lo garantizan: `tests/CreatesApplication.php` y `carga/prueba_carga.php`
abortan si la base no es `pension_carga` o si hay datos del portal configurados.

## Cómo repetirla

Desde la carpeta `pension-api`, con Docker encendido:

```sh
sh carga/ejecutar.sh
```

El script:

1. crea el entorno desde cero (base vacía con los datos iniciales);
2. ejecuta las **pruebas de concurrencia** (`tests/Concurrencia/CobrosSimultaneosTest.php`):
   envía 10 solicitudes simultáneas al servidor real sobre el mismo pedido o pago y
   revisa la base;
3. ejecuta la **prueba de carga** (`carga/prueba_carga.php`), con dos fases:
   - **normal** (180 s): 2 meseros, 2 en cocina, 1 en caja y 4 comensales (9 dispositivos);
   - **pico** (120 s): 6 meseros, 4 en cocina, 2 en caja y 18 comensales (30 dispositivos);
4. verifica la **consistencia** de los datos al terminar;
5. guarda CPU y memoria de los contenedores (`docker stats` cada 5 s).

Para borrar el entorno: `docker compose -p pension-carga -f docker-compose.carga.yml down -v`.

## Qué hace cada dispositivo (como la app)

Todos consultan los pedidos cada 5 s, como `ConsultaPeriodica` en la app.

- **Mesero:** carga la carta y las mesas, registra un pedido de 1 a 3 platos para un
  comensal, lo envía a cocina y entrega lo que cocina marcó como listo.
- **Cocina:** marca como listos los pedidos en preparación.
- **Caja:** cobra los pedidos entregados (7 de cada 10 en efectivo; el resto por QR,
  que luego confirma).
- **Comensal:** consulta «Mis pedidos» y, de vez en cuando, la carta y su fidelización.

El inicio de sesión se mide aparte, con 8 logins a la vez. El API admite 10 intentos
por minuto por IP: más serían rechazados con 429, como protección contra ataques de
contraseña. Por eso, en la carga cada dispositivo ya tiene su sesión iniciada.

## Salidas (`carga/resultados/`)

| Archivo | Contenido |
|---|---|
| `concurrencia_antes_de_corregir.txt` | La carrera reproducida antes de la corrección |
| `concurrencia_despues_de_corregir.txt` | La misma prueba después de la corrección (y 5 repeticiones) |
| `concurrencia.txt` | Pruebas de concurrencia de la última ejecución |
| `carga_resumen.txt` / `.json` | Tiempos (promedio, p95 y máximo) por endpoint, solicitudes por segundo, errores y consistencia |
| `docker_stats.csv` / `docker_stats_resumen.txt` | CPU y memoria de los contenedores |
| `ejecucion.log` | Salida completa de la ejecución |

En las tablas, un **422** es un rechazo de negocio correcto, no un error. Por ejemplo,
dos cajas intentan cobrar el mismo pedido y la segunda recibe «ya está pagado». Son
**errores** las respuestas 5xx, 401, 403, 404, 405 y 429, y las solicitudes sin respuesta.
