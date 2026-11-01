<?php

namespace App\Actions\Inventory\ItemCategory;

use App\Exceptions\ApiException;
use App\Models\Inventory\ItemCategory;
use Illuminate\Database\QueryException;

final class DeleteItemCategoryAction {

    /**
     * Em uso por itens do inventário (FK) → 409.
     *
     * @param mixed $id
     * @return void
     * @throws ApiException
     */
    public function handle(mixed $id): void {
        $itemCategory = ItemCategory::findOrFailByKey($id, 'messages.item_categories.not_found');

        try {
            $itemCategory->delete();
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.item_categories.in_use', 409);
            }

            throw $exception;
        }
    }
}
