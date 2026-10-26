<?php

namespace App\Services\Inventory\Unit;

use App\DTO\Inventory\Unit\SaveUnitDTO;
use App\Models\Inventory\Unit;
use App\Repositories\Inventory\UnitRepository;

class CreateUnitService {

    public function __construct(
        private readonly UnitRepository $unitRepository
    ) {
    }

    /**
     * @param SaveUnitDTO $dto
     * @return Unit
     */
    public function execute(SaveUnitDTO $dto): Unit {
        return $this->unitRepository->save($dto->toArray());
    }
}
