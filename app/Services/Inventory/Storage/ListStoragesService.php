<?php

namespace App\Services\Inventory\Storage;

use App\Repositories\Inventory\StorageRepository;
use Illuminate\Support\Collection;

class ListStoragesService {

    public function __construct(
        private readonly StorageRepository $storageRepository
    ) {
    }

    /**
     * @return Collection
     */
    public function execute(): Collection {
        return $this->storageRepository->all(order_by: 'name');
    }
}
