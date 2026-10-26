<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory {

    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'name' => 'Quilograma',
            'abbreviation' => 'kg',
        ];
    }
}
