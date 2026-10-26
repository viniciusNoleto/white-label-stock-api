<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Storage extends Model {

    use HasFactory;

    protected $table = 'storages';

    protected $fillable = [
        'name',
        'description',
    ];
}
