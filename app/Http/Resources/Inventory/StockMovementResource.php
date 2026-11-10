<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Movimentação de estoque. Os ids (`item_id`, `product_id`…) viram `null` quando o registro
 * some; código e nomes copiados na hora continuam.
 * `quantity` com sinal (positivo entra, negativo sai, `"0.0000"` = transferência); `balance` =
 * quantidade do item logo depois.
 */
class StockMovementResource extends JsonResource {

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'item_id' => $this->item_id,
            'item_code' => $this->item_code,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'unit_abbreviation' => $this->unit_abbreviation,
            'quantity' => $this->quantity,
            'balance' => $this->balance,
            'storage_id' => $this->storage_id,
            'storage_name' => $this->storage_name,
            'previous_storage_name' => $this->previous_storage_name,
            'delivery_package_id' => $this->delivery_package_id,
            'user_id' => $this->user_id,
            'user_name' => $this->user_name,
            'created_at' => $this->created_at,
        ];
    }
}
