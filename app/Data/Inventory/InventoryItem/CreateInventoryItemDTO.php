<?php

namespace App\Data\Inventory\InventoryItem;

use App\Http\Requests\InventoryItem\CreateInventoryItemRequest;
use Spatie\LaravelData\Data;

class CreateInventoryItemDTO extends Data {

    /**
     * @param string $name
     * @param string $quantity
     * @param int $unit_id
     * @param int|null $storage_id
     * @param int[] $category_ids sem repetição
     * @param array<int, array{id: int, quantity_required: string}> $components
     */
    public function __construct(
        public string $name,
        public string $quantity,
        public int    $unit_id,
        public ?int   $storage_id,
        public array  $category_ids,
        public array  $components,
    ) {
    }

    /**
     * @param CreateInventoryItemRequest $request
     * @return CreateInventoryItemDTO
     */
    public static function fromRequest(CreateInventoryItemRequest $request): CreateInventoryItemDTO {
        $category_ids = collect($request->validated('category_ids') ?? [])
            ->map(fn($id) => parse_id($id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $components = collect($request->validated('components') ?? [])
            ->map(fn($component) => [
                'id' => (int)$component['id'],
                'quantity_required' => (string)$component['quantity_required'],
            ])
            ->all();

        return new self(
            name: $request->validated('name'),
            quantity: (string)($request->validated('quantity') ?? 0),
            unit_id: (int)$request->validated('unit_id'),
            storage_id: parse_id($request->validated('storage_id')),
            category_ids: $category_ids,
            components: $components,
        );
    }
}
