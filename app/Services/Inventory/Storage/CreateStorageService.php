<?php

namespace App\Services\Inventory\Storage;

use App\DTO\Inventory\Storage\SaveStorageDTO;
use App\Models\Inventory\Storage;
use App\Repositories\Inventory\StorageRepository;

class CreateStorageService {

    public function __construct(
        private readonly StorageRepository $storageRepository
    ) {
    }

    /**
     * @param SaveStorageDTO $dto
     * @return Storage
     */
    public function execute(SaveStorageDTO $dto): Storage {
        return $this->storageRepository->save($dto->toArray());
    }
}
