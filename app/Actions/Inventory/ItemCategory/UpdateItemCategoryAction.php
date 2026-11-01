<?php

namespace App\Actions\Inventory\ItemCategory;

use App\Data\Inventory\ItemCategory\SaveItemCategoryDTO;
use App\Models\Inventory\ItemCategory;

final class UpdateItemCategoryAction {

    /**
     * @param mixed $id
     * @param SaveItemCategoryDTO $dto
     * @return ItemCategory
     */
    public function handle(mixed $id, SaveItemCategoryDTO $dto): ItemCategory {
        $itemCategory = ItemCategory::findOrFailByKey($id, 'messages.item_categories.not_found');
        $itemCategory->update($dto->toArray());

        return $itemCategory->fresh();
    }
}
