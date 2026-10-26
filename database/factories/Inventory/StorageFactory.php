<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\Storage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Storage>
 */
class StorageFactory extends Factory {

    protected $model = Storage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'name' => 'Depósito A',
            'description' => null,
        ];
    }
}
