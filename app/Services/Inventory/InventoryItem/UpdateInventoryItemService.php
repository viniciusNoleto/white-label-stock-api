<?php

namespace App\Services\Inventory\InventoryItem;

use App\DTO\Inventory\InventoryItem\UpdateInventoryItemDTO;
use App\Exceptions\ApiException;
use App\Models\Inventory\InventoryItem;
use App\Repositories\Inventory\InventoryItemRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

class UpdateInventoryItemService {

    private const NOT_FOUND = 'messages.inventory_items.not_found';

    public function __construct(
        private readonly InventoryItemRepository $inventoryItemRepository
    ) {
    }

    /**
     * `quantity_add`/`quantity_subtract` são aplicados no banco (`quantity ± n`), sem ler antes:
     * duas alterações simultâneas nunca se sobrescrevem.
     *
     * @param mixed $id
     * @param UpdateInventoryItemDTO $dto
     * @return InventoryItem
     * @throws ApiException
     */
    public function execute(mixed $id, UpdateInventoryItemDTO $dto): InventoryItem {
        $item_id = parse_id($id) ?? throw new ModelNotFoundException(self::NOT_FOUND);

        try {
            $affected = match (true) {
                $dto->quantity_subtract !== null => $this->inventoryItemRepository->decrementQuantity($item_id, $dto->quantity_subtract, $this->attributes($dto)),
                $dto->quantity_add !== null => $this->inventoryItemRepository->incrementQuantity($item_id, $dto->quantity_add, $this->attributes($dto)),
                default => $this->inventoryItemRepository->updateById($item_id, $this->attributes($dto)),
            };
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.inventory_items.invalid_reference');
            }

            throw $exception;
        }

        if (!$affected) {
            throw new ModelNotFoundException(self::NOT_FOUND);
        }

        return $this->inventoryItemRepository->find($item_id, message_key: self::NOT_FOUND);
    }

    /**
     * Mensagem de sucesso conforme o que foi alterado.
     *
     * @param UpdateInventoryItemDTO $dto
     * @return string
     */
    public function successMessage(UpdateInventoryItemDTO $dto): string {
        return match (true) {
            $dto->changesName() => 'messages.inventory_items.name_updated',
            $dto->has_storage_id => 'messages.inventory_items.storage_updated',
            default => 'messages.inventory_items.quantity_updated',
        };
    }

    /**
     * `updated_at` é preenchido pelo Eloquent em update/increment/decrement.
     *
     * @param UpdateInventoryItemDTO $dto
     * @return array<string, mixed>
     */
    private function attributes(UpdateInventoryItemDTO $dto): array {
        $attributes = [];

        if ($dto->quantity !== null) {
            $attributes['quantity'] = $dto->quantity;
        }

        if ($dto->changesName()) {
            $attributes['name'] = $dto->name;
        }

        if ($dto->has_storage_id) {
            $attributes['storage_id'] = $dto->storage_id;
        }

        return $attributes;
    }
}
