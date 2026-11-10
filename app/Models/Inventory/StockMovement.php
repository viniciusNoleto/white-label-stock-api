<?php

namespace App\Models\Inventory;

use App\Enum\Inventory\StockMovementTypeEnum;
use App\Enum\Inventory\WriteOffReasonEnum;
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
}
