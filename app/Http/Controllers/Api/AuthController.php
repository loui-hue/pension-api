<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // El campo "email" acepta el correo o el teléfono: los comensales que
        // registra el mesero entran con su número de celular.
        $request->validate([
            'email' => 'required|string',
            'password' => 'required',
        ]);

        // El correo no distingue mayúsculas (el teclado del celular suele poner la
        // primera en mayúscula); la contraseña sí.
        $usuario = mb_strtolower(trim($request->email));
        $campo = str_contains($usuario, '@') ? 'email' : 'phone';

        $user = User::where($campo, $usuario)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Credenciales incorrectas'
            ], 401);
        }
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login exitoso',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
                'must_change_password' => $user->must_change_password,
            ]
        ]);
    }

    /** El usuario reemplaza su contraseña (obligatorio si tenía una temporal). */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed|different:current_password',
        ], [
            'password.min' => 'La contraseña nueva debe tener al menos 6 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.different' => 'La contraseña nueva debe ser distinta de la actual.',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual no es correcta',
                'errors' => ['current_password' => ['La contraseña actual no es correcta']],
            ], 422);
        }
        if ($user->phone && $request->password === $user->phone) {
            return response()->json([
                'message' => 'No uses tu número de celular como contraseña',
                'errors' => ['password' => ['No uses tu número de celular como contraseña']],
            ], 422);
        }

        $user->update([
            'password' => $request->password,   // el cast "hashed" la cifra
            'must_change_password' => false,
        ]);

        return response()->json(['message' => 'Contraseña actualizada']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada exitosamente'
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'roles' => $user->roles->pluck('name'),
        ]);
    }
}