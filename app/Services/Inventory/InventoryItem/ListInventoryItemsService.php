<?php

namespace App\Services\Inventory\InventoryItem;

use App\DTO\Inventory\InventoryItem\ListInventoryItemsDTO;
use App\Http\Resources\Inventory\InventoryItemListResource;
use App\Repositories\Inventory\InventoryItemRepository;
use Illuminate\Http\Request;

class ListInventoryItemsService {

    public function __construct(
        private readonly InventoryItemRepository $inventoryItemRepository
    ) {
    }

    /**
     * @param Request $request
     * @return array{items: mixed, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function execute(Request $request): array {
        $dto = ListInventoryItemsDTO::fromRequest($request);
        $paginator = $this->inventoryItemRepository->filterAndPaginate($dto);

        return [
            'items' => InventoryItemListResource::collection($paginator->items()),
            'meta' => [
                'page' => $dto->page,
                'per_page' => $dto->per_page,
                'total' => $paginator->total(),
                'last_page' => max(1, $paginator->lastPage()),
            ],
        ];
    }
}
