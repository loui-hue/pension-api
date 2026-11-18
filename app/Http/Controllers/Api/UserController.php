<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /** Roles del personal que el administrador puede asignar desde «Usuarios». */
    public const ROLES_PERSONAL = ['mesero', 'cocina', 'caja', 'administrador'];

    private const MENSAJES_PERSONAL = [
        'name.required' => 'Escribe el nombre.',
        'email.required' => 'Escribe el correo.',
        'email.email' => 'El correo no es válido.',
        'email.unique' => 'Ya existe un usuario con ese correo.',
        'phone.regex' => 'El celular debe tener solo números (7 a 15 dígitos).',
        'phone.unique' => 'Ya existe un usuario con ese celular.',
        'password.required' => 'Escribe la contraseña temporal.',
        'password.min' => 'La contraseña temporal debe tener al menos 6 caracteres.',
        'role.required' => 'Elige el rol.',
        'role.in' => 'El rol debe ser mesero, cocina, caja o administrador.',
    ];

    /**
     * El administrador crea una cuenta del personal con una contraseña temporal:
     * la persona debe cambiarla en su primer ingreso.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|regex:/^[0-9]{7,15}$/|unique:users,phone',
            'password' => 'required|string|min:6',
            'role' => 'required|in:' . implode(',', self::ROLES_PERSONAL),
        ], self::MENSAJES_PERSONAL);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => trim($validated['name']),
                'email' => mb_strtolower(trim($validated['email'])),
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'must_change_password' => true,
                'status' => 'activo',
            ]);
            $user->roles()->attach(Role::firstOrCreate(['name' => $validated['role']])->id);
            return $user;
        });

        return response()->json($user->load('roles'), 201);
    }

    public function show($id)
    {
        $user = User::with('roles')->findOrFail($id);
        return response()->json($user);
    }

    /** Edita nombre, correo, celular y rol de una cuenta del personal. */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        if ($user->hasRole('cliente')) {
            return response()->json(['message' => 'Las cuentas de los comensales no se editan desde «Usuarios del personal».'], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|regex:/^[0-9]{7,15}$/|unique:users,phone,' . $user->id,
            'role' => 'sometimes|required|in:' . implode(',', self::ROLES_PERSONAL),
            'status' => 'sometimes|in:activo,inactivo',
        ], self::MENSAJES_PERSONAL);

        // El administrador no puede quitarse su propio rol (se quedaría sin acceso).
        if ($user->is($request->user()) && isset($validated['role']) && $validated['role'] !== 'administrador') {
            return response()->json(['message' => 'No puedes quitarte tu propio rol de administrador.'], 422);
        }

        DB::transaction(function () use ($user, $validated) {
            $datos = collect($validated)->except('role')->all();
            if (isset($datos['name'])) {
                $datos['name'] = trim($datos['name']);
            }
            if (isset($datos['email'])) {
                $datos['email'] = mb_strtolower(trim($datos['email']));
            }
            $user->update($datos);
            if (isset($validated['role'])) {
                $user->roles()->sync([Role::firstOrCreate(['name' => $validated['role']])->id]);
            }
        });

        return response()->json($user->fresh()->load('roles'));
    }

    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);
        if ($user->is($request->user())) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 422);
        }
        if ($user->hasRole('cliente')) {
            return response()->json(['message' => 'Las cuentas de los comensales no se eliminan desde «Usuarios del personal».'], 422);
        }
        // Borrar la cuenta borraría en cascada los pedidos que tenga a su nombre.
        if ($user->orders()->exists()) {
            return response()->json(['message' => "{$user->name} tiene pedidos registrados a su nombre: no se puede eliminar."], 422);
        }
        $nombre = $user->name;
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();   // cierra sus sesiones abiertas
            $user->delete();
        });
        return response()->json(['message' => "Cuenta de {$nombre} eliminada"]);
    }
}
