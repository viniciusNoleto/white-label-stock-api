<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('abbreviation', 20);
            $table->timestamps();
        });

        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('color_hex', 7);
            $table->timestamps();
        });

        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();
        });

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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('inventory_item_has_component');
        Schema::dropIfExists('inventory_item_has_category');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('storages');
        Schema::dropIfExists('item_categories');
        Schema::dropIfExists('units');
    }
};
