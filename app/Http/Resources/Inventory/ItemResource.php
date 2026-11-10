<?php

namespace App\Http\Resources\Inventory;

use App\Models\Inventory\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Item (registro físico) com o produto, o armazenamento e quanto dele está reservado em pacotes pendentes.
 * Datas `produced_at`/`expires_at` em `Y-m-d`.
 *
 * @mixin Item
 */
class ItemResource extends JsonResource {

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'quantity' => $this->quantity,
            'reserved_quantity' => $this->reserved_quantity ?? '0.0000',
            'produced_at' => $this->produced_at->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'is_atomic' => $this->product->is_atomic,
                'unit' => [
                    'id' => $this->product->unit->id,
                    'name' => $this->product->unit->name,
                    'abbreviation' => $this->product->unit->abbreviation,
                ],
            ],
            'storage' => $this->storage ? [
                'id' => $this->storage->id,
                'name' => $this->storage->name,
            ] : null,
        ];
    }
}
