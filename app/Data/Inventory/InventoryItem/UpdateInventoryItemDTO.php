<?php

namespace App\Data\Inventory\InventoryItem;

use App\Http\Requests\InventoryItem\UpdateInventoryItemRequest;
use Spatie\LaravelData\Data;

/**
 * Atualização parcial: só os campos presentes no payload são alterados.
 * `storage_id: null` (presente) desvincula o armazenamento; `category_ids` (presente) substitui
 * todas as categorias do item (`[]` remove todas).
 */
class UpdateInventoryItemDTO extends Data {

    public function __construct(
        public ?string $name,
        public ?string $quantity,
        public ?string $quantity_add,
        public ?string $quantity_subtract,
        public ?int    $unit_id,
        public ?array  $category_ids,
        public bool    $has_storage_id,
        public ?int    $storage_id,
    ) {
    }

    /**
     * @param UpdateInventoryItemRequest $request
     * @return UpdateInventoryItemDTO
     */
    public static function fromRequest(UpdateInventoryItemRequest $request): UpdateInventoryItemDTO {
        $toString = fn(string $key) => $request->validated($key) === null ? null : (string)$request->validated($key);

        return new self(
            name: $request->validated('name'),
            quantity: $toString('quantity'),
            quantity_add: $toString('quantity_add'),
            quantity_subtract: $toString('quantity_subtract'),
            unit_id: $request->has('unit_id') ? (int)$request->validated('unit_id') : null,
            category_ids: $request->has('category_ids')
                ? collect($request->validated('category_ids'))->map(fn($id) => parse_id($id))->filter()->unique()->values()->all()
                : null,
            has_storage_id: $request->has('storage_id'),
            storage_id: parse_id($request->validated('storage_id')),
        );
    }

    /**
     * @return bool
     */
    public function changesName(): bool {
        return $this->name !== null;
    }

    /**
     * @return bool
     */
    public function changesQuantity(): bool {
        return $this->quantity !== null || $this->quantity_add !== null || $this->quantity_subtract !== null;
    }

    /**
     * Quantos grupos de campos o payload altera (quantidade, nome, unidade, categorias, armazenamento).
     *
     * @return int
     */
    public function changedFieldsCount(): int {
        return count(array_filter([
            $this->changesQuantity(),
            $this->changesName(),
            $this->unit_id !== null,
            $this->category_ids !== null,
            $this->has_storage_id,
        ]));
    }
}
