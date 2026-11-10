<?php

namespace App\Data\Inventory\Item;

use App\Http\Requests\Item\CreateItemsRequest;
use Spatie\LaravelData\Data;

class CreateItemsDTO extends Data {

    /**
     * @param int $product_id
     * @param string $quantity atômico: quantos itens gerar (lote); a granel: quantidade do registro. Padrão 1, com 4 casas
     * @param string $produced_at `Y-m-d`; padrão hoje no fuso do negócio
     * @param int|null $storage_id
     * @param bool $consume_components consome a receita do produto (`quantity_required × quantity`)
     */
    public function __construct(
        public int     $product_id,
        public string  $quantity,
        public string  $produced_at,
        public ?int    $storage_id,
        public bool    $consume_components,
    ) {
    }

    /**
     * @param CreateItemsRequest $request
     * @return CreateItemsDTO
     */
    public static function fromRequest(CreateItemsRequest $request): CreateItemsDTO {
        return new self(
            product_id: (int)$request->validated('product_id'),
            quantity: to_decimal($request->validated('quantity') ?? 1),
            produced_at: $request->validated('produced_at') ?? business_today(),
            storage_id: parse_id($request->validated('storage_id')),
            consume_components: (bool)($request->validated('consume_components') ?? false),
        );
    }
}
