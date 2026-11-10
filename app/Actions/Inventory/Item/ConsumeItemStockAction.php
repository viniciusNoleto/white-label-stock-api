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

        // Decimal como string de propósito (o Postgres faz a conta em numeric, sem passar por float).
        Item::query()->whereKey($item->id)->decrement('quantity', $quantity); // @phpstan-ignore argument.type
    }
}
