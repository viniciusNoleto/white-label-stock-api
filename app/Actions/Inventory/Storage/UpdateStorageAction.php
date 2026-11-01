<?php

namespace App\Actions\Inventory\Storage;

use App\Data\Inventory\Storage\SaveStorageDTO;
use App\Models\Inventory\Storage;

final class UpdateStorageAction {

    /**
     * @param mixed $id
     * @param SaveStorageDTO $dto
     * @return Storage
     */
    public function handle(mixed $id, SaveStorageDTO $dto): Storage {
        $storage = Storage::findOrFailByKey($id, 'messages.storages.not_found');
        $storage->update($dto->toArray());

        return $storage->fresh();
    }
}
