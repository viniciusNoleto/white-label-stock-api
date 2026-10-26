<?php

namespace App\DTO\Inventory\InventoryItem;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class ListInventoryItemsDTO extends Data {

    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        public ?string $search,
        public int     $page,
        public int     $per_page,
    ) {
    }

    /**
     * `page` inválido vira 1; `per_page` inválido vira 15 e é limitado a 1..100.
     *
     * @param Request $request
     * @return ListInventoryItemsDTO
     */
    public static function fromRequest(Request $request): ListInventoryItemsDTO {
        $page = $request->query('page');
        $per_page = $request->query('per_page');

        return new self(
            search: is_string($request->query('search')) ? trim($request->query('search')) ?: null : null,
            page: max(1, is_numeric($page) ? (int)$page : 1),
            per_page: min(self::MAX_PER_PAGE, max(1, is_numeric($per_page) && (int)$per_page !== 0 ? (int)$per_page : self::DEFAULT_PER_PAGE)),
        );
    }
}
