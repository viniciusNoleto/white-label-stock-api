<?php

namespace App\Actions\Inventory\InventoryItem;

use App\Data\Inventory\InventoryItem\BuildInventoryItemDTO;
use App\Exceptions\ApiException;
use App\Models\Inventory\InventoryItem;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BuildInventoryItemAction {

    private const SCALE = 4;

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
    public function handle(mixed $id, BuildInventoryItemDTO $dto): InventoryItem {
        $item_id = parse_id($id) ?? throw new ApiException('messages.inventory_items.no_components');

        return DB::transaction(function () use ($item_id, $dto) {
            $recipe = DB::table('inventory_item_has_component')
                ->where('inventory_item_id', $item_id)
                ->get(['component_inventory_item_id', 'quantity_required']);

            if ($recipe->isEmpty()) {
                throw new ApiException('messages.inventory_items.no_components');
            }

            // Sempre na mesma ordem (id), evitando deadlock entre construções simultâneas.
            $stock = InventoryItem::query()
                ->whereIn('id', $recipe->pluck('component_inventory_item_id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

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

                InventoryItem::query()->whereKey($current->id)->decrement('quantity', $required);
            }

            InventoryItem::query()->whereKey($item_id)->increment('quantity', $dto->quantity);

            return InventoryItem::findOrFailByKey($item_id, 'messages.inventory_items.not_found');
        });
    }
}
