<?php

namespace App\Models\Delivery;

use App\Models\Inventory\Item;
use App\Models\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha do pacote: um item reservado e a quantidade dele no pacote.
 * Item atômico entregue é excluído do estoque: `item_id` vira `null` e o `item_code` fica.
 */
class DeliveryPackageLine extends Model {

    public const UPDATED_AT = null;

    protected $table = 'delivery_package_has_item';

    protected $fillable = [
        'delivery_package_id',
        'product_id',
        'item_id',
        'item_code',
        'quantity',
    ];

    public function product(): BelongsTo {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function item(): BelongsTo {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }
}
