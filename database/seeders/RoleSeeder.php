<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'administrador', 'description' => 'Acceso total al sistema'],
            ['name' => 'mesero', 'description' => 'Gestión de pedidos y mesas'],
            ['name' => 'cocina', 'description' => 'Recepción y preparación de pedidos'],
            ['name' => 'caja', 'description' => 'Gestión de pagos y cobranza'],
            ['name' => 'cliente', 'description' => 'Acceso al perfil y fidelización'],
        ];

        foreach ($roles as $role) {
            Role::create($role);
        }
    }
}