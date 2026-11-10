<?php

namespace App\Data\Inventory\Item;

use App\Http\Requests\Item\UpdateItemRequest;
use Spatie\LaravelData\Data;

/**
 * Atualização parcial: só os campos presentes no payload são alterados. O código não é editável.
 * `storage_id: null` desvincula o armazenamento; `expires_at: null` remove o vencimento.
 * Mudar `produced_at` sem mandar `expires_at` recalcula o vencimento pela validade do produto.
 */
class UpdateItemDTO extends Data {

    public function __construct(
        public ?string $produced_at,
        public bool    $has_expires_at,
        public ?string $expires_at,
        public bool    $has_storage_id,
        public ?int    $storage_id,
        public ?string $quantity,
        public ?string $quantity_add,
        public ?string $quantity_subtract,
    ) {
    }

    /**
     * @param UpdateItemRequest $request
     * @return UpdateItemDTO
     */
    public static function fromRequest(UpdateItemRequest $request): UpdateItemDTO {
        $decimal = fn(string $key) => $request->validated($key) === null ? null : number_format((float)$request->validated($key), 4, '.', '');

        return new self(
            produced_at: $request->validated('produced_at'),
            has_expires_at: $request->has('expires_at'),
            expires_at: $request->validated('expires_at'),
            has_storage_id: $request->has('storage_id'),
            storage_id: parse_id($request->validated('storage_id')),
            quantity: $decimal('quantity'),
            quantity_add: $decimal('quantity_add'),
            quantity_subtract: $decimal('quantity_subtract'),
        );
    }

    /**
     * @return bool
     */
    public function changesQuantity(): bool {
        return $this->quantity !== null || $this->quantity_add !== null || $this->quantity_subtract !== null;
    }

    /**
     * @return bool
     */
    public function changesOnlyQuantity(): bool {
        return $this->changesQuantity()
            && $this->produced_at === null
            && !$this->has_expires_at
            && !$this->has_storage_id;
    }
}
