<?php

namespace App\Queries\Inventory;

use App\Actions\Inventory\Product\ProductRelations;
use App\Data\Common\ListPageDTO;
use App\Models\Inventory\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListProductsQuery {

    /**
     * Busca por nome ou SKU, ordenada por nome, com o resumo do estoque e o estoque de cada componente.
     *
     * @param ListPageDTO $dto
     * @return LengthAwarePaginator
     */
    public function get(ListPageDTO $dto): LengthAwarePaginator {
        return Product::query()
            ->search($dto->search)
            ->withStock()
            ->with([
                ...ProductRelations::ALL,
                'components' => fn($query) => $query->withStock(),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(perPage: $dto->per_page, page: $dto->page);
    }
}
