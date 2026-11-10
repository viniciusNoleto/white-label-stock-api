<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O banco passa a garantir as invariantes que até aqui só o código garantia (defesa em
 * profundidade: um bug ou um UPDATE manual não deixa saldo negativo nem status inventado), e
 * ganha índice nas FKs que não tinham (Postgres não cria sozinho: sem ele, excluir um
 * armazenamento/unidade/usuário varre a tabela filha inteira).
 *
 * Os valores dos CHECKs ficam escritos aqui, não lidos dos enums: a migration precisa continuar
 * produzindo o mesmo schema mesmo que o enum mude depois (aí entra uma migration nova).
 * Se algum dado existente violar um CHECK, a migration falha inteira (DDL é transacional no
 * Postgres) e nada é alterado.
 */
return new class extends Migration {

    /** @var array<string, array{0: string, 1: string}> nome => [tabela, expressão] */
    private const CHECKS = [
        'items_quantity_non_negative' => ['items', 'quantity >= 0'],
        'delivery_package_has_item_quantity_positive' => ['delivery_package_has_item', 'quantity > 0'],
        'delivery_packages_status_valid' => ['delivery_packages', "status IN ('pending', 'delivered')"],
        'delivery_packages_delivered_at_matches_status' => ['delivery_packages', "(status = 'delivered') = (delivered_at IS NOT NULL)"],
        'products_shelf_life_positive' => ['products', 'shelf_life_days IS NULL OR shelf_life_days > 0'],
        'products_next_item_number_positive' => ['products', 'next_item_number >= 1'],
        'product_has_component_quantity_positive' => ['product_has_component', 'quantity_required > 0'],
        'product_has_component_not_itself' => ['product_has_component', 'product_id <> component_product_id'],
        'stock_movements_type_valid' => ['stock_movements', "type IN ('entry', 'production', 'consumption', 'adjustment', 'transfer', 'delivery', 'write_off')"],
        'stock_movements_reason_valid' => ['stock_movements', "reason IS NULL OR (type = 'write_off' AND reason IN ('expired', 'damaged', 'lost', 'other'))"],
    ];

    /** @var array<int, array{0: string, 1: string}> [tabela, coluna] */
    private const FOREIGN_KEY_INDEXES = [
        ['items', 'storage_id'],
        ['products', 'unit_id'],
        ['product_has_category', 'item_category_id'],
        ['stock_movements', 'user_id'],
        ['stock_movements', 'delivery_package_id'],
        ['access_tokens', 'user_id'],
        ['refresh_tokens', 'user_id'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void {
        foreach (self::CHECKS as $name => [$table, $expression]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        foreach (self::FOREIGN_KEY_INDEXES as [$table, $column]) {
            Schema::table($table, fn(Blueprint $blueprint) => $blueprint->index($column));
        }

        // Limpeza periódica dos tokens vencidos (`auth:prune-tokens`) filtra por aqui.
        Schema::table('access_tokens', fn(Blueprint $table) => $table->index('expires_at'));
        Schema::table('refresh_tokens', fn(Blueprint $table) => $table->index('expires_at'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('refresh_tokens', fn(Blueprint $table) => $table->dropIndex(['expires_at']));
        Schema::table('access_tokens', fn(Blueprint $table) => $table->dropIndex(['expires_at']));

        foreach (self::FOREIGN_KEY_INDEXES as [$table, $column]) {
            Schema::table($table, fn(Blueprint $blueprint) => $blueprint->dropIndex([$column]));
        }

        foreach (array_reverse(self::CHECKS, true) as $name => [$table]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
