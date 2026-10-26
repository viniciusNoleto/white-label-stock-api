<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\ItemCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemCategory>
 */
class ItemCategoryFactory extends Factory {

    protected $model = ItemCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'name' => 'Matéria-prima',
            'color_hex' => '#123456',
        ];
    }
}
