<?php

namespace App\Models\Inventory;

use App\Enum\Delivery\DeliveryPackageStatusEnum;
use App\Models\Concerns\FindsOrFailsByKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Item: registro físico de um produto, com código próprio para ser encontrado.
 * Atômico → `quantity` sempre 1; a granel → a quantidade do registro (lote).
 * `expires_at` = `produced_at` + validade do produto (gravado na criação).
 * `quantity` é decimal(15,4) e sai como string (`"2.5000"`).
 */
class Item extends Model {

    use FindsOrFailsByKey, HasFactory;

    /** Janela do filtro "vencendo": de hoje até daqui a N dias. */
    public const EXPIRING_DAYS = 7;

    protected $table = 'items';

    protected $fillable = [
        'product_id',
        'code',
        'quantity',
        'storage_id',
        'produced_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'produced_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    public function product(): BelongsTo {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function storage(): BelongsTo {
        return $this->belongsTo(Storage::class, 'storage_id', 'id');
    }

    /**
     * Linhas de pacotes de entrega **pendentes** (a reserva do estoque).
     *
     * @return QueryBuilder
     */
    public static function reservedLinesQuery(): QueryBuilder {
        return DB::table('delivery_package_has_item')
            ->join('delivery_packages', 'delivery_packages.id', '=', 'delivery_package_has_item.delivery_package_id')
            ->where('delivery_packages.status', DeliveryPackageStatusEnum::PENDING->value);
    }

    /**
     * Adiciona `reserved_quantity`: quanto do item está separado em pacotes pendentes (`"0.0000"` quando nada).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withReservedQuantity(Builder $query): void {
        $query->select('items.*')->selectSub(
            self::reservedLinesQuery()->whereColumn('delivery_package_has_item.item_id', 'items.id')
                ->selectRaw('COALESCE(SUM(delivery_package_has_item.quantity), 0)::numeric(15, 4)'),
            'reserved_quantity'
        );
    }

    /**
     * Ordem de saída FEFO: vence primeiro, sai primeiro (sem validade por último), depois o mais antigo.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function firstExpiringFirst(Builder $query): void {
        $query->orderByRaw('items.expires_at ASC NULLS LAST')
            ->orderBy('items.produced_at')
            ->orderBy('items.id');
    }
}
