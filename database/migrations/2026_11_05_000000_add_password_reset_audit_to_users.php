<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de quién restableció la contraseña de un comensal y cuándo
     * (recuperación asistida por el personal, con verificación presencial).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('password_reset_by')->nullable()->after('must_change_password')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('password_reset_at')->nullable()->after('password_reset_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('password_reset_by');
            $table->dropColumn('password_reset_at');
        });
    }
};
