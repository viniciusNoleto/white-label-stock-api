<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separa **produto** (o modelo: unidade, categorias, receita, validade, atômico ou a granel) de
 * **item** (cada registro físico no estoque, com código próprio, produção e vencimento).
 * Substitui `inventory_items` e suas tabelas de apoio; os dados antigos não são migrados.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::dropIfExists('delivery_package_has_item');
        Schema::dropIfExists('inventory_item_has_component');
        Schema::dropIfExists('inventory_item_has_category');
        Schema::dropIfExists('inventory_items');

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('sku', 40)->nullable()->unique();
            $table->foreignId('unit_id')->constrained('units');
            $table->boolean('is_atomic')->default(true);
            $table->unsignedInteger('shelf_life_days')->nullable();
            // Próximo número da sequência dos códigos automáticos dos itens (`{SKU}-0001`).
            $table->unsignedInteger('next_item_number')->default(1);
            $table->timestamps();
        });

        Schema::create('product_has_category', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('item_category_id')->constrained('item_categories');
            $table->timestamp('created_at')->nullable();

            $table->primary(['product_id', 'item_category_id']);
        });

        Schema::create('product_has_component', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products');
            $table->decimal('quantity_required', 15, 4);
            $table->timestamp('created_at')->nullable();

            $table->primary(['product_id', 'component_product_id']);
            $table->index('component_product_id');
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->string('code', 80)->unique();
            $table->decimal('quantity', 15, 4);
            $table->foreignId('storage_id')->nullable()->constrained('storages');
            $table->date('produced_at');
            $table->date('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index('product_id');
        });

        // Linha do pacote = um item reservado. `item_code` guarda o código: o item atômico sai do
        // estoque (é excluído) na entrega e a linha continua contando o que foi entregue.
        Schema::create('delivery_package_has_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_package_id')->constrained('delivery_packages')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('item_code', 80);
            $table->decimal('quantity', 15, 4);
            $table->timestamp('created_at')->nullable();

            $table->index('delivery_package_id');
            $table->index('item_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('delivery_package_has_item');
        Schema::dropIfExists('items');
        Schema::dropIfExists('product_has_component');
        Schema::dropIfExists('product_has_category');
        Schema::dropIfExists('products');

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->decimal('quantity', 15, 4)->default(0);
            $table->foreignId('unit_id')->constrained('units');
            $table->foreignId('storage_id')->nullable()->constrained('storages');
            $table->timestamps();
        });

        Schema::create('inventory_item_has_category', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')->constrained('inventory_items');
            $table->foreignId('item_category_id')->constrained('item_categories');
            $table->timestamp('created_at')->nullable();

            $table->primary(['inventory_item_id', 'item_category_id']);
        });

        Schema::create('inventory_item_has_component', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')->constrained('inventory_items');
            $table->foreignId('component_inventory_item_id')->constrained('inventory_items');
            $table->decimal('quantity_required', 15, 4);
            $table->timestamp('created_at')->nullable();

            $table->primary(['inventory_item_id', 'component_inventory_item_id']);
        });

        Schema::create('delivery_package_has_item', function (Blueprint $table) {
            $table->foreignId('delivery_package_id')->constrained('delivery_packages')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items');
            $table->decimal('quantity', 15, 4);
            $table->timestamp('created_at')->nullable();

            $table->primary(['delivery_package_id', 'inventory_item_id']);
            $table->index('inventory_item_id');
        });
    }
};
