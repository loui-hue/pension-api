<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pagos por QR fijo o transferencia con confirmación manual:
     * el método guarda la imagen del QR y los datos de la cuenta de la pensión,
     * y el pago queda "pendiente" hasta que caja verifica el depósito.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('requires_confirmation')->default(false)->after('active');
            $table->string('qr_image_path')->nullable()->after('requires_confirmation');
            $table->string('bank_name')->nullable()->after('qr_image_path');
            $table->string('account_number')->nullable()->after('bank_name');
            $table->string('account_holder')->nullable()->after('account_number');
        });

        DB::table('payment_methods')
            ->whereIn(DB::raw('LOWER(name)'), ['qr', 'transferencia'])
            ->update(['requires_confirmation' => true]);

        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('reference');
            $table->foreignId('confirmed_by')->nullable()->after('expires_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['expires_at', 'confirmed_at']);
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['requires_confirmation', 'qr_image_path', 'bank_name', 'account_number', 'account_holder']);
        });
    }
};
