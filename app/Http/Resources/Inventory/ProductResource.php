<?php

namespace App\Http\Resources\Inventory;

use App\Models\Inventory\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Produto com unidade, categorias, receita (`components`, com o estoque e o reservado de cada componente)
 * e o resumo do estoque: `stock_quantity` (soma dos itens), `reserved_quantity` (em pacotes
 * pendentes), `expired_quantity` (vencidos) e `items_count` (registros).
 *
 * @mixin Product
 */
class ProductResource extends JsonResource {

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        /** @var Collection<int, Product> $components receita (o Larastan não resolve o genérico da relação autorreferente) */
        $components = $this->components;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'is_atomic' => $this->is_atomic,
            'shelf_life_days' => $this->shelf_life_days,
            'stock_quantity' => $this->stock_quantity,
            'reserved_quantity' => $this->reserved_quantity,
            'expired_quantity' => $this->expired_quantity,
            'items_count' => (int)$this->items_count,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'unit' => [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
                'abbreviation' => $this->unit->abbreviation,
            ],
            'categories' => $this->categories->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->map(fn($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'color_hex' => $category->color_hex,
            ])->values(),
            'components' => $components->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->map(fn(Product $component) => [
                'id' => $component->id,
                'name' => $component->name,
                'is_atomic' => $component->is_atomic,
                'quantity_required' => $component->pivot->quantity_required,
                'stock_quantity' => $component->stock_quantity,
                'reserved_quantity' => $component->reserved_quantity,
                'expired_quantity' => $component->expired_quantity,
            ])->values(),
        ];
    }
}
