<?php

namespace App\Actions\Inventory\Item;

use App\Data\Inventory\Item\UpdateItemDTO;
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

    /**
     * Código, armazenamento, datas e — só a granel — quantidade (definir, somar ou subtrair).
     * O item fica travado durante a alteração: a quantidade nunca fica negativa nem abaixo do
     * que está reservado em pacotes pendentes, mesmo com alterações simultâneas.
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

                $item->update($attributes);
                $item->touch();

                return Item::query()->withReservedQuantity()->with(['product.unit', 'storage'])->findOrFail($item->id);
            });
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.items.invalid_reference');
            }

            if (is_unique_violation($exception)) {
                throw new ApiException('messages.items.code_taken');
            }

            throw $exception;
        }
    }

    /**
     * @param Item $item
     * @param UpdateItemDTO $dto
     * @return array<string, mixed>
     */
    private function attributes(Item $item, UpdateItemDTO $dto): array {
        $attributes = [];

        if ($dto->code !== null) {
            $attributes['code'] = $dto->code;
        }

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
