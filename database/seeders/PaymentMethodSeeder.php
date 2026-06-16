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
            ['name' => 'QR', 'description' => 'Pago mediante código QR', 'active' => true],
            ['name' => 'Transferencia', 'description' => 'Transferencia bancaria', 'active' => true],
        ];

        foreach ($methods as $method) {
            PaymentMethod::create($method);
        }
    }
}