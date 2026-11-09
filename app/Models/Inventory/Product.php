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
 */
class Product extends Model {

    use FindsOrFailsByKey, HasFactory;

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

    public function unit(): BelongsTo {
        return $this->belongsTo(Unit::class, 'unit_id', 'id');
    }

    public function categories(): BelongsToMany {
        return $this->belongsToMany(ItemCategory::class, 'product_has_category', 'product_id', 'item_category_id')
            ->withPivot('created_at');
    }

    /**
     * Receita: produtos consumidos para produzir uma unidade deste.
     */
    public function components(): BelongsToMany {
        return $this->belongsToMany(self::class, 'product_has_component', 'product_id', 'component_product_id')
            ->withPivot('quantity_required', 'created_at');
    }

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
                    ->where('items.expires_at', '<', now()->toDateString())
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
            ->whereLike('products.name', '%' . $search . '%')
            ->orWhereLike('products.sku', '%' . $search . '%')));
    }
}
