<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory {

    protected $model = InventoryItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'name' => 'Parafuso',
            'quantity' => '0',
            'unit_id' => Unit::factory(),
        ];
    }
}
