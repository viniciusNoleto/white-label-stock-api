<?php

namespace App\Data\Common;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * Query string das listagens paginadas simples (`search`, `page`, `per_page`).
 */
class ListPageDTO extends Data {

    public const DEFAULT_PER_PAGE = 8;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        public ?string $search,
        public int     $page,
        public int     $per_page,
    ) {
    }

    /**
     * `page` inválido vira 1; `per_page` inválido vira 8 e é limitado a 1..100.
     *
     * @param Request $request
     * @return ListPageDTO
     */
    public static function fromRequest(Request $request): ListPageDTO {
        $page = $request->query('page');
        $per_page = $request->query('per_page');

        return new self(
            search: is_string($request->query('search')) ? trim($request->query('search')) ?: null : null,
            page: max(1, is_numeric($page) ? (int)$page : 1),
            per_page: min(self::MAX_PER_PAGE, max(1, is_numeric($per_page) && (int)$per_page !== 0 ? (int)$per_page : self::DEFAULT_PER_PAGE)),
        );
    }
}
