<?php

namespace App\Services\Inventory\InventoryItem;

use App\DTO\Inventory\InventoryItem\CreateInventoryItemDTO;
use App\Exceptions\ApiException;
use App\Models\Inventory\InventoryItem;
use App\Repositories\Inventory\InventoryItemRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateInventoryItemService {

    public function __construct(
        private readonly InventoryItemRepository $inventoryItemRepository
    ) {
    }

    /**
     * Item + categorias + componentes numa transação: referência inexistente não grava nada.
     *
     * @param CreateInventoryItemDTO $dto
     * @return InventoryItem
     * @throws Throwable
     */
    public function execute(CreateInventoryItemDTO $dto): InventoryItem {
        try {
            return DB::transaction(function () use ($dto) {
                $item = $this->inventoryItemRepository->save([
                    'name' => $dto->name,
                    'quantity' => $dto->quantity,
                    'unit_id' => $dto->unit_id,
                    'storage_id' => $dto->storage_id,
                ]);

                $this->inventoryItemRepository->attachCategories($item->id, $dto->category_ids);
                $this->inventoryItemRepository->attachComponents($item->id, $dto->components);

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
