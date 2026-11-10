<?php

namespace App\Models\Inventory;

use App\Data\Inventory\StockMovement\ListStockMovementsDTO;
use App\Enum\Inventory\StockMovementTypeEnum;
use App\Enum\Inventory\WriteOffReasonEnum;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Movimentação de um item do estoque. Só inserção (via `RecordStockMovementsAction`).
 * `quantity` tem sinal (positivo entra, negativo sai, zero = transferência) e `balance` é a
 * quantidade do item logo depois; ambos decimal(15,4), saem como string.
 * Código, nomes e unidade ficam copiados: valem mesmo depois que o item/cadastro some.
 * `reason` = motivo da baixa (`WriteOffReasonEnum`), só em `write_off` e opcional.
 *
 * Colunas com cast (o Larastan lê o tipo daqui):
 * @property StockMovementTypeEnum $type
 * @property WriteOffReasonEnum|null $reason
 * @property string $quantity
 * @property string $balance
 * @property Carbon $created_at
 */
class StockMovement extends Model {

    public const UPDATED_AT = null;

    protected $table = 'stock_movements';

    protected $fillable = [
        'type',
        'item_id',
        'item_code',
        'product_id',
        'product_name',
        'unit_abbreviation',
        'quantity',
        'balance',
        'storage_id',
        'storage_name',
        'previous_storage_name',
        'reason',
        'delivery_package_id',
        'user_id',
        'user_name',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'type' => StockMovementTypeEnum::class,
            'reason' => WriteOffReasonEnum::class,
            'quantity' => 'decimal:4',
            'balance' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Filtros da listagem e do resumo (os dois respondem à mesma pergunta, um linha a linha e outro
     * somado): busca no código do item ou no nome do produto (sem diferenciar maiúsculas), tipo, motivo
     * da baixa, produto, armazenamento (na transferência, só o destino) e intervalo de datas (dias
     * inteiros no fuso do negócio, convertidos para o UTC gravado).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function filteredBy(Builder $query, ListStockMovementsDTO $dto): void {
        $query
            ->when($dto->search, fn(Builder $query, string $search) => $query->where(fn(Builder $query) => $query
                ->whereLike('item_code', '%' . escape_like($search) . '%')
                ->orWhereLike('product_name', '%' . escape_like($search) . '%')))
            ->when($dto->type, fn(Builder $query, StockMovementTypeEnum $type) => $query->where('type', $type->value))
            ->when($dto->reason, fn(Builder $query, WriteOffReasonEnum $reason) => $query->where('reason', $reason->value))
            ->when($dto->product_id, fn(Builder $query, int $id) => $query->where('product_id', $id))
            ->when($dto->storage_id, fn(Builder $query, int $id) => $query->where('storage_id', $id))
            ->when($dto->date_from, fn(Builder $query, string $date) => $query->where('created_at', '>=', business_day_start_utc($date)))
            ->when($dto->date_to, fn(Builder $query, string $date) => $query->where('created_at', '<', business_day_start_utc(Carbon::parse($date)->addDay()->toDateString())));
    }
}
