<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\PortalSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El comensal pasa de datos móviles (portal en la nube) al Wi-Fi de la pensión y
 * al revés sin que la app lo saque ni le pida otra vez la contraseña.
 */
class CambioDeRedTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;
    private const TOKEN_PORTAL = '1000000007|tokenCreadoEnElPortalConDatosMoviles1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@test.local', 'phone' => '71234567', 'password' => 'ana12345']);
        $this->ana->roles()->attach(Role::create(['name' => 'cliente']));

        config([
            'portal.modo' => 'local',
            'portal.sync_url' => 'https://portal.prueba',
            'portal.sync_token' => 'secreto-de-prueba',
        ]);
    }

    private function portalReconoce(User $usuario): void
    {
        Http::fake(['https://portal.prueba/api/me' => Http::response([
            'id' => $usuario->id, 'email' => $usuario->email, 'roles' => ['cliente'],
        ])]);
    }

    public function test_la_sesion_iniciada_en_el_portal_sirve_al_entrar_al_wifi_de_la_pension(): void
    {
        $this->portalReconoce($this->ana);

        // Sin traerla, la pensión no conoce ese token.
        $this->withToken(self::TOKEN_PORTAL)->getJson('/api/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();

        $this->withToken(self::TOKEN_PORTAL)->postJson('/api/portal/traer-sesion')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken(self::TOKEN_PORTAL)->getJson('/api/me')->assertOk()->assertJsonPath('email', 'ana@test.local');
        Http::assertSent(fn ($r) => $r->url() === 'https://portal.prueba/api/me'
            && $r->header('Authorization') === ['Bearer ' . self::TOKEN_PORTAL]);

        // Queda marcado como sesión del portal: no se reenvía al portal en la copia.
        $copia = app(PortalSync::class)->snapshot();
        $this->assertCount(0, $copia['tablas']['personal_access_tokens']);

        // Pedirlo otra vez no lo duplica ni vuelve a consultar al portal.
        $this->withToken(self::TOKEN_PORTAL)->postJson('/api/portal/traer-sesion')->assertOk();
        $this->assertSame(1, $this->ana->tokens()->count());
        Http::assertSentCount(1);
    }

    public function test_no_se_acepta_un_token_que_el_portal_rechaza(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->withToken(self::TOKEN_PORTAL)->postJson('/api/portal/traer-sesion')->assertUnauthorized();
        $this->assertSame(0, $this->ana->tokens()->count());
    }

    public function test_el_personal_no_puede_traer_sesiones_del_portal(): void
    {
        $mesero = User::create(['name' => 'Juan', 'email' => 'mesero@test.local', 'password' => 'mesero123']);
        $mesero->roles()->attach(Role::create(['name' => 'mesero']));
        $this->portalReconoce($mesero);

        $this->withToken(self::TOKEN_PORTAL)->postJson('/api/portal/traer-sesion')->assertUnauthorized();
        $this->assertSame(0, $mesero->tokens()->count());
    }

    public function test_sin_internet_en_la_pension_responde_503_y_no_crea_nada(): void
    {
        Http::fake(fn () => throw new ConnectionException('sin Internet'));

        $this->withToken(self::TOKEN_PORTAL)->postJson('/api/portal/traer-sesion')->assertStatus(503);
        $this->assertSame(0, $this->ana->tokens()->count());
    }

    public function test_al_iniciar_sesion_en_la_pension_su_token_pasa_al_portal_enseguida(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Sincronizado', 'filas' => []])]);

        $token = $this->postJson('/api/login', ['email' => '71234567', 'password' => 'ana12345'])->assertOk()->json('token');

        // Si Ana sale de la pensión con datos móviles, el portal ya tiene su sesión.
        Http::assertSentCount(1);
        $envio = Http::recorded()[0][0];
        $this->assertSame('https://portal.prueba/api/portal/sync', $envio->url());
        $this->assertSame(
            [(int) explode('|', $token)[0]],
            collect($envio['tablas']['personal_access_tokens'])->pluck('id')->all()
        );
    }
}
