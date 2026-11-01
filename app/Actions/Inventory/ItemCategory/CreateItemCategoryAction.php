<?php

namespace App\Actions\Inventory\ItemCategory;

use App\Data\Inventory\ItemCategory\SaveItemCategoryDTO;
use App\Models\Inventory\ItemCategory;

final class CreateItemCategoryAction {

    /**
     * @param SaveItemCategoryDTO $dto
     * @return ItemCategory
     */
    public function handle(SaveItemCategoryDTO $dto): ItemCategory {
        return ItemCategory::create($dto->toArray())->fresh();
    }
}
