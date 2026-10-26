<?php

namespace App\Services\Inventory\ItemCategory;

use App\Repositories\Inventory\ItemCategoryRepository;
use Illuminate\Support\Collection;

class ListItemCategoriesService {

    public function __construct(
        private readonly ItemCategoryRepository $itemCategoryRepository
    ) {
    }

    /**
     * @return Collection
     */
    public function execute(): Collection {
        return $this->itemCategoryRepository->all(order_by: 'name');
    }
}
