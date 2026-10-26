<?php

namespace App\Repositories\Inventory;

use App\Base\Repository\BaseRepository;
use App\Models\Inventory\Unit;

class UnitRepository extends BaseRepository {

    public function __construct() {
        $this->setModel(Unit::class);
    }
}
