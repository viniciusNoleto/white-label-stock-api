<?php

namespace App\Models\Inventory;

use App\Enum\Delivery\DeliveryPackageStatusEnum;
use App\Models\Concerns\FindsOrFailsByKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * `quantity`, `quantity_required` e `packaged_quantity` são decimal(15,4) e saem como string (`"2.5000"`), como vêm do Postgres.
 */
class InventoryItem extends Model {

    use FindsOrFailsByKey, HasFactory;

    protected $table = 'inventory_items';

    protected $fillable = [
        'name',
        'quantity',
        'unit_id',
        'storage_id',
    ];

    public function unit(): BelongsTo {
        return $this->belongsTo(Unit::class, 'unit_id', 'id');
    }

    public function storage(): BelongsTo {
        return $this->belongsTo(Storage::class, 'storage_id', 'id');
    }

    public function categories(): BelongsToMany {
        return $this->belongsToMany(ItemCategory::class, 'inventory_item_has_category', 'inventory_item_id', 'item_category_id')
            ->withPivot('created_at');
    }

    /**
     * Receita: itens consumidos para construir uma unidade deste item.
     */
    public function components(): BelongsToMany {
        return $this->belongsToMany(self::class, 'inventory_item_has_component', 'inventory_item_id', 'component_inventory_item_id')
            ->withPivot('quantity_required', 'created_at');
    }

    /**
     * Adiciona `packaged_quantity`: soma do item em pacotes de entrega pendentes (`"0.0000"` quando nenhum).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withPackagedQuantity(Builder $query): void {
        $query->select('inventory_items.*')->selectSub(
            DB::table('delivery_package_has_item')
                ->join('delivery_packages', 'delivery_packages.id', '=', 'delivery_package_has_item.delivery_package_id')
                ->whereColumn('delivery_package_has_item.inventory_item_id', 'inventory_items.id')
                ->where('delivery_packages.status', DeliveryPackageStatusEnum::PENDING->value)
                ->selectRaw('COALESCE(SUM(delivery_package_has_item.quantity), 0)::numeric(15, 4)'),
            'packaged_quantity'
        );
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $search): void {
        $query->when($search, fn(Builder $query) => $query->whereLike('name', '%' . $search . '%'));
    }
}
