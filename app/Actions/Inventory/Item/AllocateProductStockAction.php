<?php

namespace App\Actions\Inventory\Item;

use App\Exceptions\ApiException;
use App\Models\Inventory\Item;
use App\Models\Inventory\Product;

final class AllocateProductStockAction {

    private const SCALE = 4;

    /**
     * Escolhe de quais itens do produto sai `$quantity`, só no que está livre
     * (`quantidade − reservado em pacotes pendentes`) e dentro da validade (vencido não vai para
     * produção nem entrega), em ordem FEFO: vence primeiro, sai primeiro.
     * Atômico → `$quantity` itens inteiros; a granel → quantos lotes forem precisos.
     * Os itens do produto ficam travados até o fim da transação (deve rodar dentro de uma):
     * duas operações simultâneas não conseguem usar o mesmo estoque.
     *
     * @param Product $product
     * @param string $quantity com 4 casas
     * @param string $insufficient_key mensagem do 422 quando falta estoque (`:name`, `:required`, `:available`)
     * @return array<int, array{item: Item, quantity: string}>
     * @throws ApiException
     */
    public function handle(Product $product, string $quantity, string $insufficient_key = 'messages.items.insufficient_stock'): array {
        if ($product->is_atomic && !is_whole_number($quantity, self::SCALE)) {
            throw new ApiException('messages.items.atomic_integer', 422, ['name' => $product->name]);
        }

        // Trava na ordem do id (evita deadlock) e ordena em memória por FEFO.
        $items = Item::query()
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $reserved = Item::reservedLinesQuery()
            ->whereIn('delivery_package_has_item.item_id', $items->pluck('id')->all())
            ->groupBy('delivery_package_has_item.item_id')
            ->selectRaw('delivery_package_has_item.item_id, SUM(delivery_package_has_item.quantity) AS total')
            ->pluck('total', 'item_id');

        $ordered = $items->sortBy([
            fn(Item $a, Item $b) => ($a->expires_at === null) <=> ($b->expires_at === null),
            fn(Item $a, Item $b) => $a->expires_at?->getTimestamp() <=> $b->expires_at?->getTimestamp(),
            fn(Item $a, Item $b) => $a->produced_at->getTimestamp() <=> $b->produced_at->getTimestamp(),
            fn(Item $a, Item $b) => $a->id <=> $b->id,
        ]);

        $today = now()->startOfDay();
        $ordered = $ordered->reject(fn(Item $item) => $item->expires_at !== null && $item->expires_at->lt($today));

        $remaining = $quantity;
        $available = '0';
        $allocations = [];

        foreach ($ordered as $item) {
            $free = bcsub($item->quantity, (string)($reserved->get($item->id) ?? '0'), self::SCALE);

            if (bccomp($free, '0', self::SCALE) <= 0) {
                continue;
            }

            $available = bcadd($available, $free, self::SCALE);

            if (bccomp($remaining, '0', self::SCALE) > 0) {
                $take = bccomp($free, $remaining, self::SCALE) < 0 ? $free : $remaining;
                $allocations[] = ['item' => $item, 'quantity' => $take];
                $remaining = bcsub($remaining, $take, self::SCALE);
            }
        }

        if (bccomp($remaining, '0', self::SCALE) > 0) {
            throw new ApiException($insufficient_key, 422, [
                'name' => $product->name,
                'required' => format_decimal($quantity),
                'available' => format_decimal($available),
            ]);
        }

        return $allocations;
    }
}
