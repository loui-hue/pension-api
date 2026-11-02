<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentMethod;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['name' => 'Efectivo', 'description' => 'Pago en efectivo', 'active' => true],
            ['name' => 'QR', 'description' => 'Pago mediante código QR', 'active' => true, 'requires_confirmation' => true],
            ['name' => 'Transferencia', 'description' => 'Transferencia bancaria', 'active' => true, 'requires_confirmation' => true],
            ['name' => 'Tarjeta', 'description' => 'Pago con tarjeta de débito/crédito', 'active' => true],
        ];

        foreach ($methods as $method) {
            PaymentMethod::firstOrCreate(['name' => $method['name']], $method);
        }
    }
}