<?php

namespace App\Http\Resources\Delivery;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pacote de entrega com os itens aninhados (`quantity` = quantidade no pacote,
 * `current_quantity` = estoque atual do item).
 */
class DeliveryPackageResource extends JsonResource {

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'delivered_at' => $this->delivered_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => $this->items->map(fn($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->pivot->quantity,
                'current_quantity' => $item->quantity,
                'unit' => [
                    'id' => $item->unit->id,
                    'name' => $item->unit->name,
                    'abbreviation' => $item->unit->abbreviation,
                ],
            ])->values(),
        ];
    }
}
