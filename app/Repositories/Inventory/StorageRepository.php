<?php

namespace App\Repositories\Inventory;

use App\Base\Repository\BaseRepository;
use App\Models\Inventory\Storage;

class StorageRepository extends BaseRepository {

    public function __construct() {
        $this->setModel(Storage::class);
    }
}
