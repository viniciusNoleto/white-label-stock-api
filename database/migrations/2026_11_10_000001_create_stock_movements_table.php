<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das movimentações dos itens do estoque (entrada, produção, consumo, ajuste,
 * transferência, entrega e baixa). Só inserção: nada aqui é editado.
 * Código do item, nome do produto/armazenamento/usuário ficam copiados na linha — o histórico
 * continua legível depois que o item sai do estoque ou o cadastro é excluído (as FKs viram `null`).
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('item_code', 80);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name', 255);
            $table->string('unit_abbreviation', 20)->nullable();
            // Com sinal: positivo entra, negativo sai, zero só muda de lugar (transferência).
            $table->decimal('quantity', 15, 4);
            // Quantidade do item logo depois da movimentação.
            $table->decimal('balance', 15, 4);
            $table->foreignId('storage_id')->nullable()->constrained('storages')->nullOnDelete();
            $table->string('storage_name', 255)->nullable();
            $table->string('previous_storage_name', 255)->nullable();
            $table->foreignId('delivery_package_id')->nullable()->constrained('delivery_packages')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 255)->nullable();
            $table->timestamp('created_at')->index();

            $table->index('type');
            $table->index('item_id');
            $table->index('product_id');
            $table->index('storage_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('stock_movements');
    }
};
