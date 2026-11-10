<?php

namespace App\Actions\Inventory\Item;

use App\Exceptions\ApiException;
use App\Models\Inventory\Item;
use App\Models\Inventory\Product;

final class AllocateSelectedItemsAction {

    /**
     * Alternativa ao FEFO de `AllocateProductStockAction` para produtos atômicos: usa exatamente os
     * itens escolhidos (um por unidade). Cada item precisa ser do produto, estar dentro da validade e
     * livre (fora de outros pacotes pendentes). Os itens ficam travados até o fim da transação
     * (deve rodar dentro de uma): duas operações simultâneas não conseguem usar o mesmo item.
     *
     * @param Product $product
     * @param string $quantity com 4 casas; precisa bater com a quantidade de itens escolhidos
     * @param int[] $item_ids sem repetição
     * @return array<int, array{item: Item, quantity: string}>
     * @throws ApiException
     */
    public function handle(Product $product, string $quantity, array $item_ids): array {
        if (!$product->is_atomic) {
            throw new ApiException('messages.delivery_packages.selection_atomic_only', 422, ['name' => $product->name]);
        }

        if (bccomp($quantity, (string)count($item_ids), DECIMAL_SCALE) !== 0) {
            throw new ApiException('messages.delivery_packages.selection_count', 422, [
                'name' => $product->name,
                'quantity' => format_decimal($quantity),
                'count' => count($item_ids),
            ]);
        }

        // Mesma ordem global de locks da reserva FEFO e da entrega (`Item::lockedInStockOrder`).
        $items = Item::query()
            ->whereIn('id', $item_ids)
            ->lockedInStockOrder()
            ->get();

        if ($items->count() !== count($item_ids) || $items->contains(fn(Item $item) => $item->product_id !== $product->id)) {
            throw new ApiException('messages.delivery_packages.invalid_selection', 422, ['name' => $product->name]);
        }

        $reserved = Item::reservedLinesQuery()
            ->whereIn('delivery_package_has_item.item_id', $item_ids)
            ->pluck('delivery_package_has_item.item_id')
            ->all();

        $today = business_today();

        foreach ($items as $item) {
            if ($item->isExpired($today)) {
                throw new ApiException('messages.delivery_packages.selection_expired', 422, ['code' => $item->code]);
            }

            if (in_array($item->id, $reserved, true)) {
                throw new ApiException('messages.delivery_packages.selection_reserved', 422, ['code' => $item->code]);
            }
        }

        return $items->map(fn(Item $item) => ['item' => $item, 'quantity' => $item->quantity])->values()->all();
    }
}
