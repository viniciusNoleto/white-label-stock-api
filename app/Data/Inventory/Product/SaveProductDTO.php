<?php

namespace App\Data\Inventory\Product;

use App\Http\Requests\Product\SaveProductRequest;
use Spatie\LaravelData\Data;

class SaveProductDTO extends Data {

    /**
     * @param string $name
     * @param string|null $sku em maiúsculas, espaços viram `-`; vazio → `null`
     * @param int $unit_id
     * @param bool $is_atomic padrão `true` (um item por unidade)
     * @param int|null $shelf_life_days dias de validade após a produção; `null` = não vence
     * @param int[] $category_ids sem repetição
     * @param array<int, array{id: int, quantity_required: string}> $components quantidade com 4 casas (escala da coluna)
     */
    public function __construct(
        public string  $name,
        public ?string $sku,
        public int     $unit_id,
        public bool    $is_atomic,
        public ?int    $shelf_life_days,
        public array   $category_ids,
        public array   $components,
    ) {
    }

    /**
     * @param SaveProductRequest $request
     * @return SaveProductDTO
     */
    public static function fromRequest(SaveProductRequest $request): SaveProductDTO {
        $sku = trim((string)$request->validated('sku'));

        return new self(
            name: trim($request->validated('name')),
            sku: $sku === '' ? null : mb_strtoupper(preg_replace('/\s+/', '-', $sku)),
            unit_id: (int)$request->validated('unit_id'),
            is_atomic: (bool)($request->validated('is_atomic') ?? true),
            shelf_life_days: $request->validated('shelf_life_days') === null ? null : (int)$request->validated('shelf_life_days'),
            category_ids: collect($request->validated('category_ids') ?? [])
                ->map(fn($id) => parse_id($id))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            components: collect($request->validated('components') ?? [])
                ->map(fn($component) => [
                    'id' => (int)$component['id'],
                    'quantity_required' => number_format((float)$component['quantity_required'], 4, '.', ''),
                ])
                ->values()
                ->all(),
        );
    }
}
