<?php

namespace App\Actions\Delivery\DeliveryPackage;

use App\Enum\Delivery\DeliveryPackageStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Delivery\DeliveryPackage;
use App\Models\Inventory\InventoryItem;
use Illuminate\Support\Facades\DB;

final class SyncDeliveryPackageItemsAction {

    private const SCALE = 4;

    /**
     * Substitui os itens do pacote. Só empacota o que está livre no estoque:
     * `quantidade do item − já empacotado em outros pacotes pendentes`.
     * Deve rodar dentro de uma transação: as linhas dos itens ficam travadas até o fim dela,
     * então dois pacotes simultâneos não conseguem separar o mesmo estoque.
     *
     * @param DeliveryPackage $package
     * @param array<int, array{id: int, quantity: string}> $items
     * @return void
     * @throws ApiException
     */
    public function handle(DeliveryPackage $package, array $items): void {
        $ids = array_column($items, 'id');

        // Sempre na mesma ordem (id), evitando deadlock entre operações simultâneas.
        $stock = InventoryItem::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($stock->count() !== count($ids)) {
            throw new ApiException('messages.delivery_packages.invalid_reference');
        }

        $packaged = DB::table('delivery_package_has_item')
            ->join('delivery_packages', 'delivery_packages.id', '=', 'delivery_package_has_item.delivery_package_id')
            ->where('delivery_packages.status', DeliveryPackageStatusEnum::PENDING->value)
            ->where('delivery_packages.id', '!=', $package->id)
            ->whereIn('delivery_package_has_item.inventory_item_id', $ids)
            ->groupBy('delivery_package_has_item.inventory_item_id')
            ->selectRaw('delivery_package_has_item.inventory_item_id, SUM(delivery_package_has_item.quantity) AS total')
            ->pluck('total', 'inventory_item_id');

        foreach ($items as $item) {
            $current = $stock->get($item['id']);
            $available = bcsub($current->quantity, (string)($packaged->get($item['id']) ?? '0'), self::SCALE);

            if (bccomp($item['quantity'], $available, self::SCALE) > 0) {
                throw new ApiException('messages.delivery_packages.insufficient_stock', 422, [
                    'name' => $current->name,
                    'required' => format_decimal($item['quantity']),
                    'available' => format_decimal(bccomp($available, '0', self::SCALE) < 0 ? '0' : $available),
                ]);
            }
        }

        $package->items()->detach();
        $package->items()->attach(
            collect($items)->mapWithKeys(fn($item) => [
                $item['id'] => ['quantity' => $item['quantity'], 'created_at' => now()],
            ])->all()
        );
    }
}
