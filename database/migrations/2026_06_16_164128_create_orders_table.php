<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('table_id')->constrained('tables')->onDelete('cascade');
            $table->enum('status', ['pendiente', 'en_preparacion', 'listo', 'entregado', 'pagado', 'cancelado'])->default('pendiente');
            $table->text('notes')->nullable();
            $table->decimal('total', 8, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
