<?php

namespace App\Data\Inventory\StockMovement;

use App\Data\Common\ListPageDTO;
use App\Enum\Inventory\StockMovementTypeEnum;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class ListStockMovementsDTO extends Data {

    /**
     * @param string|null $search busca no código do item ou no nome do produto
     * @param StockMovementTypeEnum|null $type
     * @param int|null $product_id
     * @param int|null $storage_id
     * @param string|null $date_from `Y-m-d`, inclusive
     * @param string|null $date_to `Y-m-d`, inclusive
     * @param int $page
     * @param int $per_page
     */
    public function __construct(
        public ?string                $search,
        public ?StockMovementTypeEnum $type,
        public ?int                   $product_id,
        public ?int                   $storage_id,
        public ?string                $date_from,
        public ?string                $date_to,
        public int                    $page,
        public int                    $per_page,
    ) {
    }

    /**
     * Filtros inválidos são ignorados; paginação como em `ListPageDTO`.
     *
     * @param Request $request
     * @return ListStockMovementsDTO
     */
    public static function fromRequest(Request $request): ListStockMovementsDTO {
        $page = ListPageDTO::fromRequest($request);
        $type = $request->query('type');

        return new self(
            search: $page->search,
            type: is_string($type) ? StockMovementTypeEnum::tryFrom($type) : null,
            product_id: parse_id($request->query('product_id')),
            storage_id: parse_id($request->query('storage_id')),
            date_from: parse_date($request->query('date_from')),
            date_to: parse_date($request->query('date_to')),
            page: $page->page,
            per_page: $page->per_page,
        );
    }
}
