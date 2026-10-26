<?php

namespace App\Services\Inventory\Storage;

use App\Exceptions\ApiException;
use App\Repositories\Inventory\StorageRepository;
use Illuminate\Database\QueryException;

class DeleteStorageService {

    public function __construct(
        private readonly StorageRepository $storageRepository
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
            $this->storageRepository->delete($id, message_key: 'messages.storages.not_found');
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.storages.in_use', 409);
            }

            throw $exception;
        }
    }
}
