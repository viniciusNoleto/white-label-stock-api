<?php

namespace App\Actions\Delivery\DeliveryPackage;

use App\Actions\Inventory\Item\AllocateProductStockAction;
use App\Exceptions\ApiException;
use App\Models\Delivery\DeliveryPackage;
use App\Models\Delivery\DeliveryPackageLine;
use App\Models\Inventory\Product;

final class SyncDeliveryPackageItemsAction {

    public function __construct(
        private readonly AllocateProductStockAction $allocate,
    ) {
    }

    /**
     * Substitui o conteúdo do pacote: para cada produto, reserva itens livres em ordem FEFO
     * (atômico: N itens com código; a granel: dos lotes que vencem primeiro). As reservas antigas
     * do pacote são desfeitas antes, então ele pode reaproveitar os próprios itens.
     * Deve rodar dentro de uma transação (os itens ficam travados até o fim dela).
     *
     * @param DeliveryPackage $package
     * @param array<int, array{product_id: int, quantity: string}> $items
     * @return void
     * @throws ApiException
     */
    public function handle(DeliveryPackage $package, array $items): void {
        $products = Product::query()->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');

        if ($products->count() !== count($items)) {
            throw new ApiException('messages.delivery_packages.invalid_reference');
        }

        $package->lines()->delete();

        // Sempre na mesma ordem (id do produto), evitando deadlock entre operações simultâneas.
        foreach (collect($items)->sortBy('product_id') as $entry) {
            $product = $products->get($entry['product_id']);
            $allocations = $this->allocate->handle($product, $entry['quantity'], 'messages.delivery_packages.insufficient_stock');

            foreach ($allocations as $allocation) {
                DeliveryPackageLine::create([
                    'delivery_package_id' => $package->id,
                    'product_id' => $product->id,
                    'item_id' => $allocation['item']->id,
                    'item_code' => $allocation['item']->code,
                    'quantity' => $allocation['quantity'],
                ]);
            }
        }
    }
}
