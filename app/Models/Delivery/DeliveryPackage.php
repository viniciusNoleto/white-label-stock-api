<?php

namespace App\Models\Delivery;

use App\Enum\Delivery\DeliveryPackageStatusEnum;
use App\Models\Concerns\FindsOrFailsByKey;
use App\Models\Inventory\InventoryItem;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Pacote de entrega: itens do estoque com quantidade + anotações livres.
 * `quantity` do pivot é decimal(15,4) e sai como string (`"2.0000"`).
 */
class DeliveryPackage extends Model {

    use FindsOrFailsByKey, HasFactory;

    protected $table = 'delivery_packages';

    protected $fillable = [
        'status',
        'notes',
        'delivered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'status' => DeliveryPackageStatusEnum::class,
            'delivered_at' => 'datetime',
        ];
    }

    public function items(): BelongsToMany {
        return $this->belongsToMany(InventoryItem::class, 'delivery_package_has_item', 'delivery_package_id', 'inventory_item_id')
            ->withPivot('quantity', 'created_at');
    }

    public function isDelivered(): bool {
        return $this->status === DeliveryPackageStatusEnum::DELIVERED;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ofStatus(Builder $query, ?DeliveryPackageStatusEnum $status): void {
        $query->when($status, fn(Builder $query) => $query->where('status', $status->value));
    }
}
