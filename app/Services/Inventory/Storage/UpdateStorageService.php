<?php

namespace App\Services\Inventory\Storage;

use App\DTO\Inventory\Storage\SaveStorageDTO;
use App\Models\Inventory\Storage;
use App\Repositories\Inventory\StorageRepository;

class UpdateStorageService {

    public function __construct(
        private readonly StorageRepository $storageRepository
    ) {
    }

    /**
     * @param mixed $id
     * @param SaveStorageDTO $dto
     * @return Storage
     */
    public function execute(mixed $id, SaveStorageDTO $dto): Storage {
        return $this->storageRepository->update($id, $dto->toArray(), message_key: 'messages.storages.not_found');
    }
}
