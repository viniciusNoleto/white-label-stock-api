<?php

namespace App\Actions\Delivery\DeliveryPackage;

use App\Enum\Delivery\DeliveryPackageStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Delivery\DeliveryPackage;
use App\Models\Inventory\InventoryItem;
use App\Queries\Delivery\ListDeliveryPackagesQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverDeliveryPackageAction {

    private const SCALE = 4;

    private const NOT_FOUND = 'messages.delivery_packages.not_found';

    /**
     * Marca o pacote como entregue e subtrai os itens do estoque. Pacote e itens ficam travados
     * até o fim da transação: o mesmo pacote não é entregue duas vezes e o saldo nunca fica
     * negativo. Falta de estoque (ex.: subtraído manualmente depois de empacotar) desfaz tudo.
     *
     * @param mixed $id
     * @return DeliveryPackage
     * @throws Throwable
     */
    public function handle(mixed $id): DeliveryPackage {
        $package_id = parse_id($id) ?? throw new ModelNotFoundException(self::NOT_FOUND);

        return DB::transaction(function () use ($package_id) {
            $package = DeliveryPackage::query()->lockForUpdate()->find($package_id)
                ?? throw new ModelNotFoundException(self::NOT_FOUND);

            if ($package->isDelivered()) {
                throw new ApiException('messages.delivery_packages.already_delivered');
            }

            $lines = DB::table('delivery_package_has_item')
                ->where('delivery_package_id', $package->id)
                ->get(['inventory_item_id', 'quantity']);

            // Sempre na mesma ordem (id), evitando deadlock entre operações simultâneas.
            $stock = InventoryItem::query()
                ->whereIn('id', $lines->pluck('inventory_item_id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lines as $line) {
                $current = $stock->get($line->inventory_item_id);

                if (bccomp($current->quantity, $line->quantity, self::SCALE) < 0) {
                    throw new ApiException('messages.inventory_items.insufficient_stock', 422, [
                        'name' => $current->name,
                        'required' => format_decimal($line->quantity),
                        'available' => format_decimal($current->quantity),
                    ]);
                }

                InventoryItem::query()->whereKey($current->id)->decrement('quantity', $line->quantity);
            }

            $package->update([
                'status' => DeliveryPackageStatusEnum::DELIVERED,
                'delivered_at' => now(),
            ]);

            return $package->fresh(ListDeliveryPackagesQuery::relations());
        });
    }
}
