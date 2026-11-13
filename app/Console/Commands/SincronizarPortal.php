<?php

namespace App\Console\Commands;

use App\Services\PortalSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Envía al portal en la nube la copia de los datos de los comensales.
 * Corre cada 5 minutos en el servidor de la pensión; si no hay Internet,
 * no pasa nada: se reintenta en la siguiente vuelta.
 */
class SincronizarPortal extends Command
{
    protected $signature = 'portal:sincronizar';

    protected $description = 'Envía al portal en la nube los datos de fidelización de los comensales';

    public function handle(PortalSync $portal): int
    {
        $url = rtrim((string) config('portal.sync_url'), '/');
        if (config('portal.modo') !== 'local' || $url === '') {
            $this->info('Sincronización desactivada (sin PORTAL_SYNC_URL o no es el servidor de la pensión).');
            return self::SUCCESS;
        }

        try {
            $respuesta = Http::withToken((string) config('portal.sync_token'))
                ->acceptJson()
                ->timeout(30)
                ->post("{$url}/api/portal/sync", $portal->snapshot());
        } catch (Throwable $e) {
            $this->warn('Sin conexión con el portal: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($respuesta->failed()) {
            $this->error("El portal respondió {$respuesta->status()}: " . $respuesta->body());
            return self::FAILURE;
        }

        $this->info('Portal sincronizado: ' . json_encode($respuesta->json('filas')));
        return self::SUCCESS;
    }
}
