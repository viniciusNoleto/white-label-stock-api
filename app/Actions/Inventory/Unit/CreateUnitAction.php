<?php

namespace App\Actions\Inventory\Unit;

use App\Data\Inventory\Unit\SaveUnitDTO;
use App\Models\Inventory\Unit;

final class CreateUnitAction {

    /**
     * @param SaveUnitDTO $dto
     * @return Unit
     */
    public function handle(SaveUnitDTO $dto): Unit {
        return Unit::create($dto->toArray())->fresh();
    }
}
