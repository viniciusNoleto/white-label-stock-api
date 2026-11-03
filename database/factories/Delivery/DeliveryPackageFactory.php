<?php

namespace Database\Factories\Delivery;

use App\Enum\Delivery\DeliveryPackageStatusEnum;
use App\Models\Delivery\DeliveryPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryPackage>
 */
class DeliveryPackageFactory extends Factory {

    protected $model = DeliveryPackage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'status' => DeliveryPackageStatusEnum::PENDING,
            'notes' => null,
        ];
    }

    public function delivered(): static {
        return $this->state(fn() => ['status' => DeliveryPackageStatusEnum::DELIVERED, 'delivered_at' => now()]);
    }
}
