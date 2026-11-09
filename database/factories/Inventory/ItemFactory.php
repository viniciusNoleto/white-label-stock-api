<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\Item;
use App\Models\Inventory\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory {

    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'product_id' => Product::factory(),
            'code' => fn() => 'IT-' . $this->faker->unique()->numerify('######'),
            'quantity' => '1',
            'storage_id' => null,
            'produced_at' => now()->toDateString(),
            'expires_at' => null,
        ];
    }
}
