<?php

namespace App\Actions\Inventory\Item;

use App\Actions\Inventory\StockMovement\RecordStockMovementsAction;
use App\Enum\Inventory\StockMovementTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Inventory\Item;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeleteItemAction {

    private const NOT_FOUND = 'messages.items.not_found';

    public function __construct(
        private readonly RecordStockMovementsAction $recordMovements,
    ) {
    }

    /**
     * Baixa manual (perda, vencido, quebrado). Reservado em pacote pendente → 409: tire do pacote antes.
     * Linhas de pacotes já entregues guardam o código e ficam. Fica registrada como movimentação de baixa.
     *
     * @param mixed $id
     * @return void
     * @throws Throwable
     */
    public function handle(mixed $id): void {
        $item_id = parse_id($id) ?? throw new ModelNotFoundException(self::NOT_FOUND);

        DB::transaction(function () use ($item_id) {
            $item = Item::query()->with('product')->lockForUpdate()->find($item_id) ?? throw new ModelNotFoundException(self::NOT_FOUND);

            if (Item::reservedLinesQuery()->where('delivery_package_has_item.item_id', $item->id)->exists()) {
                throw new ApiException('messages.items.reserved', 409);
            }

            $this->recordMovements->handle(StockMovementTypeEnum::WRITE_OFF, $item->product, [[
                'item' => $item,
                'quantity' => bcmul($item->quantity, '-1', 4),
                'balance' => '0',
            ]]);

            $item->delete();
        });
    }
}
