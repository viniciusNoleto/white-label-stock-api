<?php

namespace App\Actions\Delivery\DeliveryPackage;

use App\Base\Cache\DashboardCache;
use App\Data\Delivery\DeliveryPackage\SaveDeliveryPackageDTO;
use App\Enum\Delivery\DeliveryPackageStatusEnum;
use App\Models\Delivery\DeliveryPackage;
use App\Models\User;
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
     * O autor fica registrado (id e nome copiado, como nas movimentações: sobrevive à exclusão do usuário).
     *
     * @param SaveDeliveryPackageDTO $dto
     * @param User|null $author quem montou o pacote
     * @return DeliveryPackage
     * @throws Throwable
     */
    public function handle(SaveDeliveryPackageDTO $dto, ?User $author): DeliveryPackage {
        return DB::transaction(function () use ($dto, $author) {
            DashboardCache::invalidate();

            $package = DeliveryPackage::create([
                'status' => DeliveryPackageStatusEnum::PENDING,
                'recipient' => $dto->recipient,
                'notes' => $dto->notes,
                'created_by_user_id' => $author?->id,
                'created_by_user_name' => $author?->name,
            ]);

            $this->syncItems->handle($package, $dto->items);

            return $package->fresh(ListDeliveryPackagesQuery::relations());
        }, attempts: config('database.transaction_attempts'));
    }
}
