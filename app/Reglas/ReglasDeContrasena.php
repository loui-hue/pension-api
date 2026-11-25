<?php

namespace App\Reglas;

/**
 * Contraseñas que elige una persona (al cambiar la suya o la temporal que pone el
 * administrador): al menos 8 caracteres, con letras y números. Así quedan fuera
 * claves como 123456, aaaaaaaa o 12345678.
 *
 * No se revisan contraseñas filtradas en Internet: el servidor de la pensión debe
 * funcionar sin conexión. Las contraseñas que ya existen siguen sirviendo: la regla
 * solo se aplica al crear o cambiar una. La temporal del comensal (su celular) la
 * pone el sistema y no pasa por aquí.
 */
final class ReglasDeContrasena
{
    public const MINIMO = 8;

    public static function esSegura(string $password): bool
    {
        return mb_strlen($password) >= self::MINIMO
            && preg_match('/\pL/u', $password) === 1    // al menos una letra (también ñ y tildes)
            && preg_match('/\pN/u', $password) === 1;   // al menos un número
    }

    /** Mensaje para mostrar; $cual: "La contraseña nueva", "La contraseña temporal"... */
    public static function mensaje(string $cual): string
    {
        return "{$cual} debe tener al menos " . self::MINIMO . ' caracteres, con letras y números.';
    }

    /** Regla de validación de Laravel con el mensaje en español. */
    public static function regla(string $cual): \Closure
    {
        return function (string $atributo, mixed $valor, \Closure $falla) use ($cual) {
            if (!is_string($valor) || !self::esSegura($valor)) {
                $falla(self::mensaje($cual));
            }
        };
    }
}
