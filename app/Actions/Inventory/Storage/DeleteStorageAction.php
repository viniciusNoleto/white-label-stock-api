<?php

namespace App\Actions\Inventory\Storage;

use App\Exceptions\ApiException;
use App\Models\Inventory\Storage;
use Illuminate\Database\QueryException;

final class DeleteStorageAction {

    /**
     * Em uso por itens do inventário (FK) → 409.
     *
     * @param mixed $id
     * @return void
     * @throws ApiException
     */
    public function handle(mixed $id): void {
        $storage = Storage::findOrFailByKey($id, 'messages.storages.not_found');

        try {
            $storage->delete();
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.storages.in_use', 409);
            }

            throw $exception;
        }
    }
}
