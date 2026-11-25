<?php

namespace Tests\Unit;

use App\Reglas\ReglasDeContrasena;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Contraseñas que elige una persona: al menos 8 caracteres, con letras y números. */
class ReglasDeContrasenaTest extends TestCase
{
    public static function contrasenas(): array
    {
        return [
            'muy corta' => ['abc12', false],
            '7 caracteres' => ['rosa202', false],
            'solo números' => ['12345678', false],
            'solo letras' => ['contrasena', false],
            'la de antes de 6' => ['123456', false],
            'letras y números' => ['pension24', true],
            'con ñ y tilde' => ['montaña2026', true],
            'demostración' => ['admin123', true],
            'con símbolos' => ['Nueva-clave-9', true],
        ];
    }

    #[DataProvider('contrasenas')]
    public function test_solo_acepta_contrasenas_de_8_o_mas_con_letras_y_numeros(string $password, bool $segura): void
    {
        $this->assertSame($segura, ReglasDeContrasena::esSegura($password));
    }

    public function test_el_mensaje_dice_la_regla_completa(): void
    {
        $this->assertSame(
            'La contraseña nueva debe tener al menos 8 caracteres, con letras y números.',
            ReglasDeContrasena::mensaje('La contraseña nueva')
        );
    }
}
