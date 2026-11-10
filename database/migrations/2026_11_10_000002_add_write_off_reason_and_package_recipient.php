<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Só adiciona colunas anuláveis (expand): linhas antigas continuam válidas, nada é reescrito, e o
 * código anterior segue funcionando com o banco novo (rollback do código não exige rollback do banco).
 * - `stock_movements.reason`: motivo da baixa (vencido, avaria, extravio, outro).
 * - `delivery_packages.recipient`: para quem vai o pacote.
 * - `delivery_packages.created_by_user_*`: quem montou; o nome fica copiado como nas movimentações.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('reason', 20)->nullable()->after('previous_storage_name');
            $table->index(['type', 'reason', 'created_at']);
        });

        Schema::table('delivery_packages', function (Blueprint $table) {
            $table->string('recipient', 255)->nullable()->after('status');
            $table->foreignId('created_by_user_id')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->string('created_by_user_name', 255)->nullable()->after('created_by_user_id');

            $table->index('created_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('delivery_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['recipient', 'created_by_user_name']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['type', 'reason', 'created_at']);
            $table->dropColumn('reason');
        });
    }
};
