<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemCategory extends Model {

    use HasFactory;

    protected $table = 'item_categories';

    protected $fillable = [
        'name',
        'color_hex',
    ];
}
