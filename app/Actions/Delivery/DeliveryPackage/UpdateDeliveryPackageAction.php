<?php

namespace App\Actions\Delivery\DeliveryPackage;

use App\Data\Delivery\DeliveryPackage\SaveDeliveryPackageDTO;
use App\Exceptions\ApiException;
use App\Models\Delivery\DeliveryPackage;
use App\Queries\Delivery\ListDeliveryPackagesQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UpdateDeliveryPackageAction {

    private const NOT_FOUND = 'messages.delivery_packages.not_found';

    public function __construct(
        private readonly SyncDeliveryPackageItemsAction $syncItems,
    ) {
    }

    /**
     * Substitui destinatário, anotações e itens (o autor fica o de quem criou). Pacote entregue não pode ser editado (o estoque já saiu).
     * A linha do pacote fica travada: não dá para editar e entregar ao mesmo tempo.
     *
     * @param mixed $id
     * @param SaveDeliveryPackageDTO $dto
     * @return DeliveryPackage
     * @throws Throwable
     */
    public function handle(mixed $id, SaveDeliveryPackageDTO $dto): DeliveryPackage {
        $package_id = parse_id($id) ?? throw new ModelNotFoundException(self::NOT_FOUND);

        return DB::transaction(function () use ($package_id, $dto) {
            $package = DeliveryPackage::query()->lockForUpdate()->find($package_id)
                ?? throw new ModelNotFoundException(self::NOT_FOUND);

            if ($package->isDelivered()) {
                throw new ApiException('messages.delivery_packages.already_delivered');
            }

            $package->fill(['notes' => $dto->notes, 'recipient' => $dto->recipient])->touch();

            $this->syncItems->handle($package, $dto->items);

            return $package->fresh(ListDeliveryPackagesQuery::relations());
        }, attempts: config('database.transaction_attempts'));
    }
}
