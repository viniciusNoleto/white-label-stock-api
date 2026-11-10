<?php

namespace App\Queries\Inventory;

use App\Data\Inventory\StockMovement\ListStockMovementsDTO;
use App\Models\Inventory\StockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListStockMovementsQuery {

    /**
     * Mais recentes primeiro, com os filtros de `StockMovement::filteredBy`.
     *
     * @param ListStockMovementsDTO $dto
     * @return LengthAwarePaginator
     */
    public function get(ListStockMovementsDTO $dto): LengthAwarePaginator {
        return StockMovement::query()
            ->filteredBy($dto)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $dto->per_page, page: $dto->page);
    }
}
