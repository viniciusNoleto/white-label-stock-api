<?php

namespace App\Services\Inventory\ItemCategory;

use App\DTO\Inventory\ItemCategory\SaveItemCategoryDTO;
use App\Models\Inventory\ItemCategory;
use App\Repositories\Inventory\ItemCategoryRepository;

class CreateItemCategoryService {

    public function __construct(
        private readonly ItemCategoryRepository $itemCategoryRepository
    ) {
    }

    /**
     * @param SaveItemCategoryDTO $dto
     * @return ItemCategory
     */
    public function execute(SaveItemCategoryDTO $dto): ItemCategory {
        return $this->itemCategoryRepository->save($dto->toArray());
    }
}
