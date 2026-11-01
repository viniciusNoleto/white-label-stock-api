<?php

namespace App\Actions\Inventory\Unit;

use App\Data\Inventory\Unit\SaveUnitDTO;
use App\Models\Inventory\Unit;

final class UpdateUnitAction {

    /**
     * @param mixed $id
     * @param SaveUnitDTO $dto
     * @return Unit
     */
    public function handle(mixed $id, SaveUnitDTO $dto): Unit {
        $unit = Unit::findOrFailByKey($id, 'messages.units.not_found');
        $unit->update($dto->toArray());

        return $unit->fresh();
    }
}
