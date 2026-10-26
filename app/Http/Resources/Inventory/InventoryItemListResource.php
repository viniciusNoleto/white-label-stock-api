<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Item da listagem, com unidade, armazenamento, categorias e componentes aninhados.
 */
class InventoryItemListResource extends JsonResource {

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'unit' => [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
                'abbreviation' => $this->unit->abbreviation,
            ],
            'storage' => $this->storage ? [
                'id' => $this->storage->id,
                'name' => $this->storage->name,
            ] : null,
            'categories' => $this->categories->map(fn($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'color_hex' => $category->color_hex,
            ])->values(),
            'components' => $this->components->map(fn($component) => [
                'id' => $component->id,
                'name' => $component->name,
                'quantity_required' => $component->pivot->quantity_required,
                'current_quantity' => $component->quantity,
            ])->values(),
        ];
    }
}
