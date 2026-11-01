<?php

namespace App\Data\Inventory\InventoryItem;

use App\Http\Requests\InventoryItem\BuildInventoryItemRequest;
use Spatie\LaravelData\Data;

class BuildInventoryItemDTO extends Data {

    public function __construct(
        public string $quantity,
    ) {
    }

    /**
     * Sem `quantity`, constrói uma unidade. Normalizado com 4 casas (escala da coluna) para as contas com bcmath.
     *
     * @param BuildInventoryItemRequest $request
     * @return BuildInventoryItemDTO
     */
    public static function fromRequest(BuildInventoryItemRequest $request): BuildInventoryItemDTO {
        return new self(
            quantity: number_format((float)($request->validated('quantity') ?? 1), 4, '.', ''),
        );
    }
}
