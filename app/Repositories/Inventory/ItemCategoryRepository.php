<?php

namespace App\Repositories\Inventory;

use App\Base\Repository\BaseRepository;
use App\Models\Inventory\ItemCategory;

class ItemCategoryRepository extends BaseRepository {

    public function __construct() {
        $this->setModel(ItemCategory::class);
    }
}
