<?php

namespace App\Queries\Inventory;

use App\Data\Inventory\InventoryItem\ListInventoryItemsDTO;
use App\Models\Inventory\InventoryItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListInventoryItemsQuery {

    /**
     * Busca por nome, ordenada por nome, com unidade, armazenamento, categorias e componentes.
     *
     * @param ListInventoryItemsDTO $dto
     * @return LengthAwarePaginator
     */
    public function get(ListInventoryItemsDTO $dto): LengthAwarePaginator {
        return InventoryItem::query()
            ->search($dto->search)
            ->with([
                'unit',
                'storage',
                'categories' => fn($query) => $query->orderBy('name'),
                'components' => fn($query) => $query->orderBy('name'),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(perPage: $dto->per_page, page: $dto->page);
    }
}
