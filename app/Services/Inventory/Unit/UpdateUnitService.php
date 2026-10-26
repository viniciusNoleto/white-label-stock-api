<?php

namespace App\Services\Inventory\Unit;

use App\DTO\Inventory\Unit\SaveUnitDTO;
use App\Models\Inventory\Unit;
use App\Repositories\Inventory\UnitRepository;

class UpdateUnitService {

    public function __construct(
        private readonly UnitRepository $unitRepository
    ) {
    }

    /**
     * @param mixed $id
     * @param SaveUnitDTO $dto
     * @return Unit
     */
    public function execute(mixed $id, SaveUnitDTO $dto): Unit {
        return $this->unitRepository->update($id, $dto->toArray(), message_key: 'messages.units.not_found');
    }
}
