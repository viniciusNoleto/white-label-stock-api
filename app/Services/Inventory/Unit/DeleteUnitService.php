<?php

namespace App\Services\Inventory\Unit;

use App\Exceptions\ApiException;
use App\Repositories\Inventory\UnitRepository;
use Illuminate\Database\QueryException;

class DeleteUnitService {

    public function __construct(
        private readonly UnitRepository $unitRepository
    ) {
    }

    /**
     * Em uso por itens do inventário (FK) → 409.
     *
     * @param mixed $id
     * @return void
     * @throws ApiException
     */
    public function execute(mixed $id): void {
        try {
            $this->unitRepository->delete($id, message_key: 'messages.units.not_found');
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.units.in_use', 409);
            }

            throw $exception;
        }
    }
}
