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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Item: registro físico de um produto, com código próprio para ser encontrado.
 * Atômico → `quantity` sempre 1; a granel → a quantidade do registro (lote).
 * `expires_at` = `produced_at` + validade do produto (gravado na criação).
 * `quantity` é decimal(15,4) e sai como string (`"2.5000"`).
 *
 * @property-read string|null $reserved_quantity só com o scope `withReservedQuantity()`
 *
 * Colunas com cast (o Larastan lê o tipo daqui):
 * @property string $quantity
 * @property Carbon $produced_at
 * @property Carbon|null $expires_at
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
            'quantity' => 'decimal:4',
            'produced_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    /**
     * @return BelongsTo<Storage, $this>
     */
    public function storage(): BelongsTo {
        return $this->belongsTo(Storage::class, 'storage_id', 'id');
    }

    /**
     * Vencido = `expires_at` antes de hoje no fuso do negócio (`business_today()`); vence hoje ainda vale.
     *
     * @param string|null $today `Y-m-d`; padrão `business_today()`
     * @return bool
     */
    public function isExpired(?string $today = null): bool {
        return $this->expires_at !== null && $this->expires_at->toDateString() < ($today ?? business_today());
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
     * **Única** ordem em que itens são travados (`FOR UPDATE`) no sistema inteiro: produto, depois id.
     * Reserva (FEFO ou itens escolhidos), consumo da receita e entrega percorrem produtos em ordem de
     * id e, dentro de cada um, itens em ordem de id; como todas pegam os locks na mesma ordem global,
     * duas transações nunca esperam uma pela outra em ciclo (deadlock). Qualquer código novo que trave
     * mais de um item precisa passar por aqui.
     * A linha de `products` não entra nessa ordem: quem a trava usa `Product::lockedForWrite()`
     * (`FOR NO KEY UPDATE`), que não bloqueia o `FOR KEY SHARE` das FKs gravadas com os itens já travados.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function lockedInStockOrder(Builder $query): void {
        $query->orderBy('items.product_id')->orderBy('items.id')->lockForUpdate();
    }

    /**
     * Itens que ainda podem sair do estoque: com saldo e dentro da validade (vence hoje ainda sai).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function usable(Builder $query, ?string $today = null): void {
        $today ??= business_today();

        $query->where('items.quantity', '>', 0)
            ->where(fn(Builder $query) => $query->whereNull('items.expires_at')->orWhere('items.expires_at', '>=', $today));
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
