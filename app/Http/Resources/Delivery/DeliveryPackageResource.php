<?php

namespace App\Http\Resources\Delivery;

use App\Models\Delivery\DeliveryPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pacote de entrega com os produtos aninhados (`quantity` = quantidade no pacote,
 * `stock_quantity` = estoque atual do produto) e, em cada um, os itens separados
 * (`allocations`: código e quantidade de cada item a pegar).
 *
 * @mixin DeliveryPackage
 */
class DeliveryPackageResource extends JsonResource {

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'recipient' => $this->recipient,
            'notes' => $this->notes,
            'created_by' => $this->created_by_user_name === null ? null : [
                'id' => $this->created_by_user_id,
                'name' => $this->created_by_user_name,
            ],
            'delivered_at' => $this->delivered_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => $this->lines
                ->groupBy('product_id')
                ->map(function ($lines) {
                    $product = $lines->first()->product;

                    return [
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'is_atomic' => $product->is_atomic,
                        'quantity' => $lines->reduce(fn(string $sum, $line) => bcadd($sum, $line->quantity, 4), '0.0000'),
                        'stock_quantity' => $product->stock_quantity,
                        'unit' => [
                            'id' => $product->unit->id,
                            'name' => $product->unit->name,
                            'abbreviation' => $product->unit->abbreviation,
                        ],
                        'allocations' => $lines->map(fn($line) => [
                            'item_id' => $line->item_id,
                            'code' => $line->item_code,
                            'quantity' => $line->quantity,
                        ])->values(),
                    ];
                })
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
        ];
    }
}
