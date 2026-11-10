<?php

namespace App\Http\Resources\Inventory;

use App\Models\Inventory\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Uma linha do resumo de movimentações (`SummarizeStockMovementsQuery`): total com sinal de um
 * produto num tipo (e motivo, na baixa). `product_id` vira `null` quando o produto foi excluído.
 *
 * @mixin StockMovement
 */
class StockMovementSummaryResource extends JsonResource {

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'unit_abbreviation' => $this->unit_abbreviation,
            'type' => $this->type->value,
            'reason' => $this->reason?->value,
            'quantity' => $this->getAttribute('total_quantity'),
            'movements_count' => (int)$this->getAttribute('movements_count'),
        ];
    }
}
