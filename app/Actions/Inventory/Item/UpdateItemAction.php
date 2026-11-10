<?php

namespace App\Actions\Inventory\Item;

use App\Actions\Inventory\StockMovement\RecordStockMovementsAction;
use App\Data\Inventory\Item\UpdateItemDTO;
use App\Enum\Inventory\StockMovementTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Inventory\Item;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UpdateItemAction {

    private const NOT_FOUND = 'messages.items.not_found';

    private const SCALE = 4;

    public function __construct(
        private readonly RecordStockMovementsAction $recordMovements,
    ) {
    }

    /**
     * Armazenamento, datas e — só a granel — quantidade (definir, somar ou subtrair).
     * O item fica travado durante a alteração: a quantidade nunca fica negativa nem abaixo do
     * que está reservado em pacotes pendentes, mesmo com alterações simultâneas.
     * Mudança de quantidade vira movimentação de ajuste; de armazenamento, de transferência.
     *
     * @param mixed $id
     * @param UpdateItemDTO $dto
     * @return Item
     * @throws Throwable
     */
    public function handle(mixed $id, UpdateItemDTO $dto): Item {
        $item_id = parse_id($id) ?? throw new ModelNotFoundException(self::NOT_FOUND);

        try {
            return DB::transaction(function () use ($item_id, $dto) {
                $item = Item::query()->with('product')->lockForUpdate()->find($item_id)
                    ?? throw new ModelNotFoundException(self::NOT_FOUND);

                $attributes = $this->attributes($item, $dto);

                if ($dto->changesQuantity()) {
                    $attributes['quantity'] = $this->newQuantity($item, $dto);
                }

                $previous_quantity = $item->quantity;
                $previous_storage_id = $item->storage_id;

                $item->update($attributes);
                $item->touch();

                $this->recordMovements($item, $previous_quantity, $previous_storage_id);

                return Item::query()->withReservedQuantity()->with(['product.unit', 'storage'])->findOrFail($item->id);
            });
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.items.invalid_reference');
            }

            throw $exception;
        }
    }

    /**
     * Ajuste (diferença de quantidade, com sinal) e transferência (quantidade zero), só se mudaram.
     *
     * @param Item $item já atualizado
     * @param string $previous_quantity
     * @param int|null $previous_storage_id
     * @return void
     */
    private function recordMovements(Item $item, string $previous_quantity, ?int $previous_storage_id): void {
        $delta = bcsub((string)$item->quantity, $previous_quantity, self::SCALE);

        if (bccomp($delta, '0', self::SCALE) !== 0) {
            $this->recordMovements->handle(StockMovementTypeEnum::ADJUSTMENT, $item->product, [[
                'item' => $item,
                'quantity' => $delta,
                'balance' => (string)$item->quantity,
            ]]);
        }

        if ($item->storage_id !== $previous_storage_id) {
            $this->recordMovements->handle(StockMovementTypeEnum::TRANSFER, $item->product, [[
                'item' => $item,
                'quantity' => '0',
                'balance' => (string)$item->quantity,
                'previous_storage_id' => $previous_storage_id,
            ]]);
        }
    }

    /**
     * @param Item $item
     * @param UpdateItemDTO $dto
     * @return array<string, mixed>
     */
    private function attributes(Item $item, UpdateItemDTO $dto): array {
        $attributes = [];

        if ($dto->has_storage_id) {
            $attributes['storage_id'] = $dto->storage_id;
        }

        if ($dto->produced_at !== null) {
            $attributes['produced_at'] = $dto->produced_at;

            // Nova produção sem vencimento explícito: recalcula pela validade do produto.
            if (!$dto->has_expires_at && $item->product->shelf_life_days) {
                $attributes['expires_at'] = Carbon::parse($dto->produced_at)->addDays($item->product->shelf_life_days)->toDateString();
            }
        }

        if ($dto->has_expires_at) {
            $attributes['expires_at'] = $dto->expires_at;
        }

        return $attributes;
    }

    /**
     * @param Item $item
     * @param UpdateItemDTO $dto
     * @return string
     * @throws ApiException
     */
    private function newQuantity(Item $item, UpdateItemDTO $dto): string {
        if ($item->product->is_atomic) {
            throw new ApiException('messages.items.atomic_quantity_locked');
        }

        $quantity = match (true) {
            $dto->quantity_add !== null => bcadd($item->quantity, $dto->quantity_add, self::SCALE),
            $dto->quantity_subtract !== null => bcsub($item->quantity, $dto->quantity_subtract, self::SCALE),
            default => $dto->quantity,
        };

        if (bccomp($quantity, '0', self::SCALE) < 0) {
            throw new ApiException('messages.items.insufficient_stock', 422, [
                'name' => $item->code,
                'required' => format_decimal($dto->quantity_subtract),
                'available' => format_decimal($item->quantity),
            ]);
        }

        $reserved = (string)(Item::reservedLinesQuery()->where('delivery_package_has_item.item_id', $item->id)->sum('delivery_package_has_item.quantity') ?: '0');

        if (bccomp($quantity, $reserved, self::SCALE) < 0) {
            throw new ApiException('messages.items.below_reserved', 422, ['reserved' => format_decimal($reserved)]);
        }

        return $quantity;
    }
}
