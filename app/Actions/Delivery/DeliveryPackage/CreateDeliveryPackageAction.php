<?php

namespace App\Actions\Delivery\DeliveryPackage;

use App\Data\Delivery\DeliveryPackage\SaveDeliveryPackageDTO;
use App\Enum\Delivery\DeliveryPackageStatusEnum;
use App\Models\Delivery\DeliveryPackage;
use App\Queries\Delivery\ListDeliveryPackagesQuery;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateDeliveryPackageAction {

    public function __construct(
        private readonly SyncDeliveryPackageItemsAction $syncItems,
    ) {
    }

    /**
     * Pacote pendente + itens numa transação: item inexistente ou sem estoque livre não grava nada.
     *
     * @param SaveDeliveryPackageDTO $dto
     * @return DeliveryPackage
     * @throws Throwable
     */
    public function handle(SaveDeliveryPackageDTO $dto): DeliveryPackage {
        return DB::transaction(function () use ($dto) {
            $package = DeliveryPackage::create([
                'status' => DeliveryPackageStatusEnum::PENDING,
                'notes' => $dto->notes,
            ]);

            $this->syncItems->handle($package, $dto->items);

            return $package->fresh(ListDeliveryPackagesQuery::relations());
        });
    }
}
