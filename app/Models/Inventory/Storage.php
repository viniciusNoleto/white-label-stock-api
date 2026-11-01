<?php

namespace App\Models\Inventory;

use App\Models\Concerns\FindsOrFailsByKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Storage extends Model {

    use FindsOrFailsByKey, HasFactory;

    protected $table = 'storages';

    protected $fillable = [
        'name',
        'description',
    ];
}
