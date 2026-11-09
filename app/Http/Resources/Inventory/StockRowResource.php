<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha da tela de operação (`ListStockQuery`): `kind = group` junta os itens atômicos de um
 * produto (`quantity` = quantos existem); `kind = record` é um lote a granel, mostrado como está.
 *
 * @property array{row: object, product: \App\Models\Inventory\Product} $resource
 */
class StockRowResource extends JsonResource {

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        ['row' => $row, 'product' => $product] = $this->resource;

        return [
            'key' => $row->kind === 'group' ? "product-{$row->product_id}" : "item-{$row->item_id}",
            'kind' => $row->kind,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'is_atomic' => $product->is_atomic,
                'unit' => [
                    'id' => $product->unit->id,
                    'name' => $product->unit->name,
                    'abbreviation' => $product->unit->abbreviation,
                ],
            ],
            'item' => $row->kind === 'record' ? ['id' => $row->item_id, 'code' => $row->item_code] : null,
            'quantity' => $row->quantity,
            'reserved_quantity' => $row->reserved_quantity,
            'items_count' => (int)$row->items_count,
            'next_expires_at' => $row->next_expires_at,
            'expired_quantity' => $row->expired_quantity,
            'storages' => collect(json_decode($row->storages, true))->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        ];
    }
}
