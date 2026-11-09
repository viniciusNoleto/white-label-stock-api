<?php

namespace App\Models\Delivery;

use App\Enum\Delivery\DeliveryPackageStatusEnum;
use App\Models\Concerns\FindsOrFailsByKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pacote de entrega: produtos com quantidade + anotações livres. Cada produto vira linhas
 * (`DeliveryPackageLine`) com os itens reservados para ele (FEFO).
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

    public function lines(): HasMany {
        return $this->hasMany(DeliveryPackageLine::class, 'delivery_package_id', 'id');
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
