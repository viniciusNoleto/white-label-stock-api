<?php

namespace App\DTO\Inventory\InventoryItem;

use App\Http\Requests\InventoryItem\UpdateInventoryItemRequest;
use Spatie\LaravelData\Data;

/**
 * Atualização parcial: só os campos presentes no payload são alterados.
 * `storage_id: null` (presente) desvincula o armazenamento.
 */
class UpdateInventoryItemDTO extends Data {

    public function __construct(
        public ?string $name,
        public ?string $quantity,
        public ?string $quantity_add,
        public ?string $quantity_subtract,
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
}
