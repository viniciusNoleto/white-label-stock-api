<?php

namespace App\Actions\Inventory\InventoryItem;

use App\Data\Inventory\InventoryItem\CreateInventoryItemDTO;
use App\Exceptions\ApiException;
use App\Models\Inventory\InventoryItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateInventoryItemAction {

    /**
     * Item + categorias + componentes numa transação: referência inexistente não grava nada.
     *
     * @param CreateInventoryItemDTO $dto
     * @return InventoryItem
     * @throws Throwable
     */
    public function handle(CreateInventoryItemDTO $dto): InventoryItem {
        try {
            return DB::transaction(function () use ($dto) {
                $item = InventoryItem::create([
                    'name' => $dto->name,
                    'quantity' => $dto->quantity,
                    'unit_id' => $dto->unit_id,
                    'storage_id' => $dto->storage_id,
                ])->fresh();

                if ($dto->category_ids) {
                    $item->categories()->attach($dto->category_ids, ['created_at' => now()]);
                }

                if ($dto->components) {
                    $item->components()->attach(
                        collect($dto->components)->mapWithKeys(fn($component) => [
                            $component['id'] => ['quantity_required' => $component['quantity_required'], 'created_at' => now()],
                        ])->all()
                    );
                }

                return $item;
            });
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.inventory_items.invalid_reference');
            }

            throw $exception;
        }
    }
}
