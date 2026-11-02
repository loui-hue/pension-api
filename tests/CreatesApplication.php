<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Candado: RefreshDatabase borra todas las tablas, así que las pruebas
        // solo pueden correr sobre SQLite en memoria, nunca sobre la base real.
        $conexion = $app['config']->get('database.default');
        $base = $app['config']->get("database.connections.{$conexion}.database");
        if ($conexion !== 'sqlite' || $base !== ':memory:') {
            throw new \RuntimeException("Pruebas abortadas: la conexión es '{$conexion}' ({$base}), se esperaba SQLite en memoria.");
        }

        return $app;
    }
}
