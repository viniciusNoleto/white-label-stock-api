<?php

namespace App\Models\Inventory;

use App\Models\Concerns\FindsOrFailsByKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemCategory extends Model {

    use FindsOrFailsByKey, HasFactory;

    protected $table = 'item_categories';

    protected $fillable = [
        'name',
        'color_hex',
    ];
}
