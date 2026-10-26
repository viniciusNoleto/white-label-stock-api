<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model {

    use HasFactory;

    protected $table = 'units';

    protected $fillable = [
        'name',
        'abbreviation',
    ];

    public function inventoryItems(): HasMany {
        return $this->hasMany(InventoryItem::class, 'unit_id', 'id');
    }
}
