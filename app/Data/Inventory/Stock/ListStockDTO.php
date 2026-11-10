<?php

namespace App\Data\Inventory\Stock;

use App\Data\Common\ListPageDTO;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class ListStockDTO extends Data {

    /**
     * @param string|null $search busca no nome ou SKU do produto
     * @param int|null $storage_id
     * @param string|null $expires_at `Y-m-d`: vencimento exato
     * @param int $page
     * @param int $per_page
     */
    public function __construct(
        public ?string $search,
        public ?int    $storage_id,
        public ?string $expires_at,
        public int     $page,
        public int     $per_page,
    ) {
    }

    /**
     * Filtros inválidos são ignorados; paginação como em `ListPageDTO`.
     *
     * @param Request $request
     * @return ListStockDTO
     */
    public static function fromRequest(Request $request): ListStockDTO {
        $page = ListPageDTO::fromRequest($request);

        return new self(
            search: $page->search,
            storage_id: parse_id($request->query('storage_id')),
            expires_at: parse_date($request->query('expires_at')),
            page: $page->page,
            per_page: $page->per_page,
        );
    }
}
