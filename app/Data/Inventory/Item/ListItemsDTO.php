<?php

namespace App\Data\Inventory\Item;

use App\Data\Common\ListPageDTO;
use App\Enum\Inventory\ItemExpirationFilterEnum;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class ListItemsDTO extends Data {

    public function __construct(
        public ?string                   $search,
        public ?int                      $product_id,
        public ?int                      $storage_id,
        public ?ItemExpirationFilterEnum $expiration,
        public int                       $page,
        public int                       $per_page,
    ) {
    }

    /**
     * Filtros inválidos são ignorados; paginação como em `ListPageDTO`.
     *
     * @param Request $request
     * @return ListItemsDTO
     */
    public static function fromRequest(Request $request): ListItemsDTO {
        $page = ListPageDTO::fromRequest($request);
        $expiration = $request->query('expiration');

        return new self(
            search: $page->search,
            product_id: parse_id($request->query('product_id')),
            storage_id: parse_id($request->query('storage_id')),
            expiration: is_string($expiration) ? ItemExpirationFilterEnum::tryFrom($expiration) : null,
            page: $page->page,
            per_page: $page->per_page,
        );
    }
}
