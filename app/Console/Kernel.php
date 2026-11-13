<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Copia de los datos de los comensales al portal en la nube (si está configurado).
        // Además de la copia inmediata tras cada cambio, esta revisión de cada minuto
        // pone al día el portal apenas vuelve Internet después de un corte.
        $schedule->command('portal:sincronizar')->everyMinute()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
