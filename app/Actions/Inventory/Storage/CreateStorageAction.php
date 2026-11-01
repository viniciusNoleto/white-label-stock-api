<?php

namespace App\Actions\Inventory\Storage;

use App\Data\Inventory\Storage\SaveStorageDTO;
use App\Models\Inventory\Storage;

final class CreateStorageAction {

    /**
     * @param SaveStorageDTO $dto
     * @return Storage
     */
    public function handle(SaveStorageDTO $dto): Storage {
        return Storage::create($dto->toArray())->fresh();
    }
}
