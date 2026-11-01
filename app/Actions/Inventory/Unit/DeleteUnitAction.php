<?php

namespace App\Actions\Inventory\Unit;

use App\Exceptions\ApiException;
use App\Models\Inventory\Unit;
use Illuminate\Database\QueryException;

final class DeleteUnitAction {

    /**
     * Em uso por itens do inventário (FK) → 409.
     *
     * @param mixed $id
     * @return void
     * @throws ApiException
     */
    public function handle(mixed $id): void {
        $unit = Unit::findOrFailByKey($id, 'messages.units.not_found');

        try {
            $unit->delete();
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.units.in_use', 409);
            }

            throw $exception;
        }
    }
}
