<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('delivery_packages', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
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

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('delivery_package_has_item');
        Schema::dropIfExists('delivery_packages');
    }
};
