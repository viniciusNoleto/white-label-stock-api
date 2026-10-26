<?php

namespace App\Services\Inventory\ItemCategory;

use App\Exceptions\ApiException;
use App\Repositories\Inventory\ItemCategoryRepository;
use Illuminate\Database\QueryException;

class DeleteItemCategoryService {

    public function __construct(
        private readonly ItemCategoryRepository $itemCategoryRepository
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
            $this->itemCategoryRepository->delete($id, message_key: 'messages.item_categories.not_found');
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.item_categories.in_use', 409);
            }

            throw $exception;
        }
    }
}
