<?php

namespace App\Actions\Inventory\InventoryItem;

use App\Data\Inventory\InventoryItem\UpdateInventoryItemDTO;
use App\Exceptions\ApiException;
use App\Models\Inventory\InventoryItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UpdateInventoryItemAction {

    private const NOT_FOUND = 'messages.inventory_items.not_found';

    /**
     * `quantity_add`/`quantity_subtract` são aplicados no banco (`quantity ± n`), sem ler antes:
     * duas alterações simultâneas nunca se sobrescrevem. A subtração só casa a linha se houver
     * estoque (`quantity >= n`), então o saldo nunca fica negativo, nem com subtrações concorrentes.
     * Campos e categorias vão numa transação: referência inexistente não grava nada.
     *
     * @param mixed $id
     * @param UpdateInventoryItemDTO $dto
     * @return InventoryItem
     * @throws Throwable
     */
    public function handle(mixed $id, UpdateInventoryItemDTO $dto): InventoryItem {
        $item_id = parse_id($id) ?? throw new ModelNotFoundException(self::NOT_FOUND);

        try {
            return DB::transaction(function () use ($item_id, $dto) {
                $query = InventoryItem::query()->whereKey($item_id);

                // Sem atributos (só categorias), o update ainda grava `updated_at`.
                $affected = match (true) {
                    $dto->quantity_subtract !== null => $query->clone()
                        ->where('quantity', '>=', $dto->quantity_subtract)
                        ->decrement('quantity', $dto->quantity_subtract, $this->attributes($dto)),
                    $dto->quantity_add !== null => $query->increment('quantity', $dto->quantity_add, $this->attributes($dto)),
                    default => $query->update($this->attributes($dto)),
                };

                $item = InventoryItem::findOrFailByKey($item_id, self::NOT_FOUND);

                if (!$affected) {
                    // A linha existe: só não casou porque o estoque é menor que o subtraído.
                    throw new ApiException('messages.inventory_items.insufficient_stock', 422, [
                        'name' => $item->name,
                        'required' => format_decimal($dto->quantity_subtract),
                        'available' => format_decimal($item->quantity),
                    ]);
                }

                if ($dto->category_ids !== null) {
                    $this->syncCategories($item, $dto->category_ids);
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

    /**
     * Substitui as categorias do item; as que continuam mantêm o `created_at` original.
     *
     * @param InventoryItem $item
     * @param int[] $category_ids
     * @return void
     */
    private function syncCategories(InventoryItem $item, array $category_ids): void {
        $current = $item->categories()->pluck('item_categories.id')->all();

        $item->categories()->detach(array_values(array_diff($current, $category_ids)));
        $item->categories()->attach(array_values(array_diff($category_ids, $current)), ['created_at' => now()]);
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

        if ($dto->unit_id !== null) {
            $attributes['unit_id'] = $dto->unit_id;
        }

        if ($dto->has_storage_id) {
            $attributes['storage_id'] = $dto->storage_id;
        }

        return $attributes;
    }
}
