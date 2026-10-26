<?php

namespace App\Services\Inventory\Unit;

use App\Repositories\Inventory\UnitRepository;
use Illuminate\Support\Collection;

class ListUnitsService {

    public function __construct(
        private readonly UnitRepository $unitRepository
    ) {
    }

    /**
     * @return Collection
     */
    public function execute(): Collection {
        return $this->unitRepository->all(order_by: 'name');
    }
}
