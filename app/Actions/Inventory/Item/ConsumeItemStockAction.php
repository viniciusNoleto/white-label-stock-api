<?php

namespace App\Actions\Inventory\Item;

use App\Models\Inventory\Item;
use App\Models\Inventory\Product;

final class ConsumeItemStockAction {

    /**
     * Tira a quantidade do item: atômico sai do estoque (é excluído; linhas de pacotes entregues
     * guardam o código), a granel tem a quantidade subtraída (o lote fica, mesmo zerado).
     * Quem chama já travou o item e conferiu o saldo.
     *
     * @param Item $item
     * @param Product $product
     * @param string $quantity
     * @return void
     */
    public function handle(Item $item, Product $product, string $quantity): void {
        if ($product->is_atomic) {
            Item::query()->whereKey($item->id)->delete();

            return;
        }

        Item::query()->whereKey($item->id)->decrement('quantity', $quantity);
    }
}
