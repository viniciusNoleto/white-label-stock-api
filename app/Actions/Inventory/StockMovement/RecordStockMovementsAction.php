<?php

namespace App\Actions\Inventory\StockMovement;

use App\Base\Cache\DashboardCache;
use App\Enum\Inventory\StockMovementTypeEnum;
use App\Enum\Inventory\WriteOffReasonEnum;
use App\Models\Inventory\Item;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Storage;
use Illuminate\Support\Facades\Auth;

final class RecordStockMovementsAction {

    /**
     * Grava as movimentações de itens de um mesmo produto numa só inserção, com o usuário da
     * requisição (`Auth::user()`, posto pelo middleware `auth`) como autor.
     * Deve rodar na mesma transação da alteração do estoque e **antes** de excluir o item
     * (a FK `item_id` precisa existir na inserção; depois vira `null` e o código fica).
     * Toda mudança de estoque passa por aqui, então é aqui que o painel fica velho (`DashboardCache`, depois do commit).
     *
     * @param StockMovementTypeEnum $type
     * @param Product $product
     * @param array<int, array{item: Item, quantity: string, balance: string, previous_storage_id?: int|null, reason?: WriteOffReasonEnum|null}> $entries
     *        `quantity` com sinal; `balance` = quantidade do item depois da movimentação; `reason` só na baixa
     * @param int|null $delivery_package_id
     * @return void
     */
    public function handle(StockMovementTypeEnum $type, Product $product, array $entries, ?int $delivery_package_id = null): void {
        if ($entries === []) {
            return;
        }

        $product->loadMissing('unit');

        $storage_ids = collect($entries)
            ->flatMap(fn(array $entry) => [$entry['item']->storage_id, $entry['previous_storage_id'] ?? null])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $storages = $storage_ids === [] ? collect() : Storage::query()->whereIn('id', $storage_ids)->pluck('name', 'id');
        $user = Auth::user();
        $now = now();

        StockMovement::query()->insert(array_map(fn(array $entry) => [
            'type' => $type->value,
            'item_id' => $entry['item']->id,
            'item_code' => $entry['item']->code,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_abbreviation' => $product->unit?->abbreviation,
            'quantity' => $entry['quantity'],
            'balance' => $entry['balance'],
            'storage_id' => $entry['item']->storage_id,
            'storage_name' => $entry['item']->storage_id ? $storages->get($entry['item']->storage_id) : null,
            'previous_storage_name' => isset($entry['previous_storage_id']) ? $storages->get($entry['previous_storage_id']) : null,
            'reason' => isset($entry['reason']) ? $entry['reason']->value : null,
            'delivery_package_id' => $delivery_package_id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'created_at' => $now,
        ], $entries));

        DashboardCache::invalidate();
    }
}
