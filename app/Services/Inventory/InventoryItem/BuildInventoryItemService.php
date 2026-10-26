<?php

namespace App\Services\Inventory\InventoryItem;

use App\DTO\Inventory\InventoryItem\BuildInventoryItemDTO;
use App\Exceptions\ApiException;
use App\Models\Inventory\InventoryItem;
use App\Repositories\Inventory\InventoryItemRepository;
use Illuminate\Support\Facades\DB;
use Throwable;

class BuildInventoryItemService {

    private const SCALE = 4;

    public function __construct(
        private readonly InventoryItemRepository $inventoryItemRepository
    ) {
    }

    /**
     * Consome os componentes da receita (`quantity_required × quantity`) e soma `quantity` ao item.
     * As linhas dos componentes ficam travadas até o fim da transação: duas construções
     * simultâneas não conseguem consumir o mesmo estoque. Falta de estoque desfaz tudo.
     *
     * @param mixed $id
     * @param BuildInventoryItemDTO $dto
     * @return InventoryItem
     * @throws Throwable
     */
    public function execute(mixed $id, BuildInventoryItemDTO $dto): InventoryItem {
        $item_id = parse_id($id) ?? throw new ApiException('messages.inventory_items.no_components');

        return DB::transaction(function () use ($item_id, $dto) {
            $recipe = $this->inventoryItemRepository->recipe($item_id);

            if ($recipe->isEmpty()) {
                throw new ApiException('messages.inventory_items.no_components');
            }

            $stock = $this->inventoryItemRepository->lockForUpdate($recipe->pluck('component_inventory_item_id')->all());

            foreach ($recipe as $component) {
                $current = $stock->get($component->component_inventory_item_id);
                $required = bcmul($component->quantity_required, $dto->quantity, self::SCALE);

                if (bccomp($current->quantity, $required, self::SCALE) < 0) {
                    throw new ApiException('messages.inventory_items.insufficient_stock', 422, [
                        'name' => $current->name,
                        'required' => format_decimal($required),
                        'available' => format_decimal($current->quantity),
                    ]);
                }

                $this->inventoryItemRepository->decrementQuantity($current->id, $required);
            }

            $this->inventoryItemRepository->incrementQuantity($item_id, $dto->quantity);

            return $this->inventoryItemRepository->find($item_id, message_key: 'messages.inventory_items.not_found');
        });
    }
}
