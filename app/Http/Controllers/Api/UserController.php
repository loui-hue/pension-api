<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        // Filtros opcionales: ?role=cliente&search=ana (por nombre, correo o teléfono)
        $users = User::with('roles')
            ->when($request->role, fn ($q, $role) => $q->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->when($request->search, function ($q, $search) {
                $like = '%' . mb_strtolower($search) . '%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhere('phone', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->get();
        return response()->json($users);
    }

    /**
     * Registro rápido de un comensal por el personal (mesero, caja o admin) al
     * tomar el pedido. Solo pide nombre y teléfono; el comensal entra a la app
     * con su teléfono, la contraseña temporal es ese mismo número y debe
     * cambiarla en su primer ingreso.
     */
    public function storeCustomer(Request $request)
    {
        $personal = $request->user();
        if (!$personal->hasRole('mesero') && !$personal->hasRole('caja') && !$personal->hasRole('administrador')) {
            return response()->json(['message' => 'Solo el personal puede registrar comensales'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|regex:/^[0-9]{7,15}$/|unique:users,phone',
            'email' => 'nullable|email|max:255|unique:users,email',
        ], [
            'phone.regex' => 'El teléfono debe tener solo números (7 a 15 dígitos).',
            'phone.unique' => 'Ya existe un comensal con ese teléfono.',
            'email.unique' => 'Ya existe un usuario con ese correo.',
        ]);

        $comensal = User::create([
            'name' => trim($validated['name']),
            'phone' => $validated['phone'],
            // Sin correo: uno interno y único, derivado del teléfono.
            'email' => mb_strtolower($validated['email'] ?? "{$validated['phone']}@comensal.local"),
            'password' => Hash::make($validated['phone']),
            'must_change_password' => true,   // contraseña temporal: la cambia al primer ingreso
            'status' => 'activo',
        ]);
        $comensal->roles()->attach(Role::where('name', 'cliente')->firstOrFail());

        return response()->json($comensal->load('roles'), 201);
    }

    /**
     * Recuperación asistida: el comensal olvidó su contraseña y está en la
     * pensión. El personal verifica en persona que es él y la restablece; vuelve
     * a ser temporal (su celular) y debe crear una nueva al entrar.
     */
    public function resetCustomerPassword(Request $request, $id)
    {
        $personal = $request->user();
        if (!$personal->hasRole('mesero') && !$personal->hasRole('caja') && !$personal->hasRole('administrador')) {
            return response()->json(['message' => 'Solo el personal puede restablecer contraseñas'], 403);
        }

        $comensal = User::findOrFail($id);
        // Solo cuentas de comensales: las del personal se gestionan en Usuarios.
        if (!$comensal->hasRole('cliente')) {
            return response()->json(['message' => 'Solo se puede restablecer la contraseña de un comensal'], 422);
        }
        if (!$comensal->phone) {
            return response()->json(['message' => 'El comensal no tiene celular registrado'], 422);
        }

        $comensal->update([
            'password' => $comensal->phone,   // el cast "hashed" la cifra
            'must_change_password' => true,
            'password_reset_by' => $personal->id,
            'password_reset_at' => now(),
        ]);
        // Cierra las sesiones que tuviera abiertas en otros celulares.
        $comensal->tokens()->delete();

        return response()->json([
            'message' => "Contraseña restablecida. {$comensal->name} debe entrar con su celular y crear una nueva.",
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
            'phone' => 'nullable|string',
            'role_id' => 'required|exists:roles,id',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'status' => 'activo',
        ]);

        $user->roles()->attach($request->role_id);

        return response()->json($user->load('roles'), 201);
    }

    public function show($id)
    {
        $user = User::with('roles')->findOrFail($id);
        return response()->json($user);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $user->update($request->only(['name', 'phone', 'status']));

        if ($request->has('role_id')) {
            $user->roles()->sync([$request->role_id]);
        }

        return response()->json($user->load('roles'));
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return response()->json(['message' => 'Usuario eliminado']);
    }
}
