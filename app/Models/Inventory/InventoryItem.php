<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * `quantity` e `quantity_required` são decimal(15,4) e saem como string (`"2.5000"`), como vêm do Postgres.
 */
class InventoryItem extends Model {

    use HasFactory;

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

    public function scopeSearch($query, ?string $search) {
        return $query->when($search, fn($query) => $query->whereLike('name', '%' . $search . '%'));
    }
}
