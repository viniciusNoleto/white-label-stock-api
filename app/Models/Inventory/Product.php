<?php

namespace App\Models\Inventory;

use App\Models\Concerns\FindsOrFailsByKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Produto: o modelo dos itens (unidade, categorias, receita e validade).
 * Atômico (`is_atomic`): cada unidade é um item com código próprio (armário, bolo).
 * A granel: um item guarda uma quantidade (parafusos, farinha).
 * `quantity_required` é decimal(15,4) e sai como string (`"2.5000"`).
 *
 * Colunas calculadas pelo scope `withStock()`:
 * @property-read string|null $stock_quantity
 * @property-read string|null $reserved_quantity
 * @property-read string|null $expired_quantity
 * @property-read int|null $items_count
 * @property-read object{quantity_required: string}|null $pivot na receita (`components`)
 */
class Product extends Model {

    use FindsOrFailsByKey, HasFactory;

    /** Relações carregadas em toda resposta de produto (`ProductResource`). */
    public const RESPONSE_RELATIONS = [
        'unit',
        'categories',
        'components',
    ];

    protected $table = 'products';

    protected $fillable = [
        'name',
        'sku',
        'unit_id',
        'is_atomic',
        'shelf_life_days',
        'next_item_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'is_atomic' => 'boolean',
            'shelf_life_days' => 'integer',
            'next_item_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo {
        return $this->belongsTo(Unit::class, 'unit_id', 'id');
    }

    /**
     * @return BelongsToMany<ItemCategory, $this>
     */
    public function categories(): BelongsToMany {
        return $this->belongsToMany(ItemCategory::class, 'product_has_category', 'product_id', 'item_category_id')
            ->withPivot('created_at');
    }

    /**
     * Receita: produtos consumidos para produzir uma unidade deste.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function components(): BelongsToMany {
        return $this->belongsToMany(self::class, 'product_has_component', 'product_id', 'component_product_id')
            ->withPivot('quantity_required', 'created_at');
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany {
        return $this->hasMany(Item::class, 'product_id', 'id');
    }

    /**
     * Prefixo dos códigos automáticos dos itens: o SKU ou, sem ele, `P{id}`.
     *
     * @return string
     */
    public function codePrefix(): string {
        return $this->sku ?: 'P' . $this->id;
    }

    /**
     * Trava a linha do produto para escrita com `FOR NO KEY UPDATE`, **nunca** `FOR UPDATE`.
     * Toda inserção com FK para `products` (itens, movimentações, linhas de pacote) pede `FOR KEY SHARE`
     * na linha do produto. `FOR UPDATE` conflita com ele; `FOR NO KEY UPDATE` não. Com `FOR UPDATE`, a
     * produção de P (que trava P e depois os itens do componente C) e a entrega de um pacote com C e P
     * (que trava os itens e depois grava a movimentação de P) esperavam uma pela outra em ciclo: deadlock
     * reproduzido em `tests/Concurrency/StockConcurrencyTest.php`. Duas escritas no mesmo produto
     * continuam serializadas (`NO KEY UPDATE` conflita com ele mesmo).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function lockedForWrite(Builder $query): void {
        $query->lock('for no key update');
    }

    /**
     * Adiciona `stock_quantity` (soma dos itens), `reserved_quantity` (separado em pacotes pendentes),
     * `expired_quantity` (itens vencidos, que não vão para produção nem entrega) e `items_count`
     * — todos `"0.0000"`/`0` quando não há itens.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withStock(Builder $query): void {
        $query->select('products.*')
            ->selectSub(
                DB::table('items')->whereColumn('items.product_id', 'products.id')
                    ->selectRaw('COALESCE(SUM(items.quantity), 0)::numeric(15, 4)'),
                'stock_quantity'
            )
            ->selectSub(
                Item::reservedLinesQuery()->whereColumn('delivery_package_has_item.product_id', 'products.id')
                    ->selectRaw('COALESCE(SUM(delivery_package_has_item.quantity), 0)::numeric(15, 4)'),
                'reserved_quantity'
            )
            ->selectSub(
                DB::table('items')->whereColumn('items.product_id', 'products.id')
                    ->where('items.expires_at', '<', business_today())
                    ->selectRaw('COALESCE(SUM(items.quantity), 0)::numeric(15, 4)'),
                'expired_quantity'
            )
            ->selectSub(
                DB::table('items')->whereColumn('items.product_id', 'products.id')->selectRaw('COUNT(*)'),
                'items_count'
            );
    }

    /**
     * Busca por nome ou SKU.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $search): void {
        $query->when($search, fn(Builder $query) => $query->where(fn(Builder $query) => $query
            ->whereLike('products.name', '%' . escape_like($search) . '%')
            ->orWhereLike('products.sku', '%' . escape_like($search) . '%')));
    }
}
