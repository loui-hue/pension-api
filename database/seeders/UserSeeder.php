<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'name' => 'Administrador',
            'email' => 'admin@pension.com',
            'phone' => '70000001',
            'password' => Hash::make('admin123'),
            'status' => 'activo',
        ]);
        $admin->roles()->attach(Role::where('name', 'administrador')->first());

        $mesero = User::create([
            'name' => 'Juan Mesero',
            'email' => 'mesero@pension.com',
            'phone' => '70000002',
            'password' => Hash::make('mesero123'),
            'status' => 'activo',
        ]);
        $mesero->roles()->attach(Role::where('name', 'mesero')->first());

        $cocina = User::create([
            'name' => 'Maria Cocina',
            'email' => 'cocina@pension.com',
            'phone' => '70000003',
            'password' => Hash::make('cocina123'),
            'status' => 'activo',
        ]);
        $cocina->roles()->attach(Role::where('name', 'cocina')->first());

        $caja = User::create([
            'name' => 'Pedro Caja',
            'email' => 'caja@pension.com',
            'phone' => '70000004',
            'password' => Hash::make('caja123'),
            'status' => 'activo',
        ]);
        $caja->roles()->attach(Role::where('name', 'caja')->first());

        $cliente = User::create([
            'name' => 'Ana Cliente',
            'email' => 'cliente@pension.com',
            'phone' => '70000005',
            'password' => Hash::make('cliente123'),
            'status' => 'activo',
        ]);
        $cliente->roles()->attach(Role::where('name', 'cliente')->first());
    }
}