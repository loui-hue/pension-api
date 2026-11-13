<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Candado: ninguna prueba puede salir a Internet. Una vez las pruebas enviaron
        // sus datos de prueba al portal real en la nube; ahora cualquier petición HTTP
        // no simulada con Http::fake() hace fallar la prueba.
        Http::preventStrayRequests();
    }
}
