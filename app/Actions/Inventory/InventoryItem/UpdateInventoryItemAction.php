<?php

namespace App\Actions\Inventory\InventoryItem;

use App\Data\Inventory\InventoryItem\UpdateInventoryItemDTO;
use App\Exceptions\ApiException;
use App\Models\Inventory\InventoryItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

final class UpdateInventoryItemAction {

    private const NOT_FOUND = 'messages.inventory_items.not_found';

    /**
     * `quantity_add`/`quantity_subtract` são aplicados no banco (`quantity ± n`), sem ler antes:
     * duas alterações simultâneas nunca se sobrescrevem.
     *
     * @param mixed $id
     * @param UpdateInventoryItemDTO $dto
     * @return InventoryItem
     * @throws ApiException
     */
    public function handle(mixed $id, UpdateInventoryItemDTO $dto): InventoryItem {
        $item_id = parse_id($id) ?? throw new ModelNotFoundException(self::NOT_FOUND);
        $query = InventoryItem::query()->whereKey($item_id);

        try {
            $affected = match (true) {
                $dto->quantity_subtract !== null => $query->decrement('quantity', $dto->quantity_subtract, $this->attributes($dto)),
                $dto->quantity_add !== null => $query->increment('quantity', $dto->quantity_add, $this->attributes($dto)),
                default => $query->update($this->attributes($dto)),
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

        return InventoryItem::findOrFailByKey($item_id, self::NOT_FOUND);
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
