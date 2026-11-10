<?php

namespace App\Base\Http\Controllers;

use App\Base\Traits\Response;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;

class BaseController extends Controller {

    use AuthorizesRequests, Response, ValidatesRequests;

    /**
     * Corpo padrão das listagens: `{ items, meta: { page, per_page, total, last_page } }`.
     * `last_page` é no mínimo 1 (lista vazia ainda tem uma página).
     *
     * @param LengthAwarePaginator $paginator
     * @param class-string<JsonResource> $resource
     * @return array{items: mixed, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    protected static function paginated(LengthAwarePaginator $paginator, string $resource): array {
        return [
            'items' => $resource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => max(1, $paginator->lastPage()),
            ],
        ];
    }
}
