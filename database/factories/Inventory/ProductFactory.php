<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\Product;
use App\Models\Inventory\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory {

    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'name' => 'Armário',
            'sku' => null,
            'unit_id' => Unit::factory(),
            'is_atomic' => true,
            'shelf_life_days' => null,
        ];
    }

    /**
     * Produto a granel (um item guarda uma quantidade).
     */
    public function bulk(): static {
        return $this->state(fn() => ['is_atomic' => false]);
    }
}
