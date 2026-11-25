<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de auditoría: quién hizo qué y cuándo (inicios de sesión, pedidos,
 * cobros, gestión del administrador). Solo agrega una tabla: no toca los datos
 * existentes. Se queda en la pensión (no viaja al portal: ver PortalSync::TABLAS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Si se elimina la cuenta, el registro se conserva (sin usuario) con su nombre guardado.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_role', 30)->nullable();
            $table->string('action', 40);        // categoria.accion, p. ej. cobro.confirmado
            $table->text('description');
            $table->string('subject_type', 40)->nullable();   // pedido, pago, producto...
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
