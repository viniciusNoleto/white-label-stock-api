<?php

namespace App\Services\Inventory\ItemCategory;

use App\DTO\Inventory\ItemCategory\SaveItemCategoryDTO;
use App\Models\Inventory\ItemCategory;
use App\Repositories\Inventory\ItemCategoryRepository;

class UpdateItemCategoryService {

    public function __construct(
        private readonly ItemCategoryRepository $itemCategoryRepository
    ) {
    }

    /**
     * @param mixed $id
     * @param SaveItemCategoryDTO $dto
     * @return ItemCategory
     */
    public function execute(mixed $id, SaveItemCategoryDTO $dto): ItemCategory {
        return $this->itemCategoryRepository->update($id, $dto->toArray(), message_key: 'messages.item_categories.not_found');
    }
}
