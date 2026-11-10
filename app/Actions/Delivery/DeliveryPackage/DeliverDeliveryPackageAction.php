<?php

namespace App\Actions\Delivery\DeliveryPackage;

use App\Actions\Inventory\Item\ConsumeItemStockAction;
use App\Actions\Inventory\StockMovement\RecordStockMovementsAction;
use App\Enum\Delivery\DeliveryPackageStatusEnum;
use App\Enum\Inventory\StockMovementTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Delivery\DeliveryPackage;
use App\Models\Inventory\Item;
use App\Queries\Delivery\ListDeliveryPackagesQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverDeliveryPackageAction {

    private const SCALE = 4;

    private const NOT_FOUND = 'messages.delivery_packages.not_found';

    public function __construct(
        private readonly ConsumeItemStockAction     $consume,
        private readonly RecordStockMovementsAction $recordMovements,
    ) {
    }

    /**
     * Marca o pacote como entregue e tira do estoque os itens reservados (atômicos saem do
     * estoque; lotes a granel têm a quantidade subtraída), cada linha registrada como movimentação
     * de entrega. Pacote e itens ficam travados até o fim da transação: o mesmo pacote não é
     * entregue duas vezes e o saldo nunca fica negativo.
     *
     * A validade é conferida de novo aqui: o item pode ter sido reservado válido e vencido antes da
     * entrega. Item vencido no pacote → 422 com o código; o usuário edita o pacote (a reserva FEFO
     * troca por um válido) e entrega.
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

            $lines = $package->lines()->with('product.unit')->orderBy('product_id')->orderBy('id')->get();

            // Mesma ordem global de locks da reserva (`Item::lockedInStockOrder`): produto, depois item.
            $stock = Item::query()
                ->whereIn('id', $lines->pluck('item_id')->filter()->all())
                ->lockedInStockOrder()
                ->get()
                ->keyBy('id');

            $today = business_today();

            foreach ($lines as $line) {
                $item = $line->item_id ? $stock->get($line->item_id) : null;

                if ($item?->isExpired($today)) {
                    throw new ApiException('messages.delivery_packages.expired_items', 422, ['code' => $item->code]);
                }

                if (!$item || bccomp($item->quantity, $line->quantity, self::SCALE) < 0) {
                    throw new ApiException('messages.items.insufficient_stock', 422, [
                        'name' => $line->product->name,
                        'required' => format_decimal($line->quantity),
                        'available' => format_decimal($item->quantity ?? '0'),
                    ]);
                }

                // Registra antes de consumir: o atômico é excluído no consumo.
                $this->recordMovements->handle(StockMovementTypeEnum::DELIVERY, $line->product, [[
                    'item' => $item,
                    'quantity' => bcmul($line->quantity, '-1', self::SCALE),
                    'balance' => bcsub($item->quantity, $line->quantity, self::SCALE),
                ]], $package->id);

                $this->consume->handle($item, $line->product, $line->quantity);
            }

            $package->update([
                'status' => DeliveryPackageStatusEnum::DELIVERED,
                'delivered_at' => now(),
            ]);

            return $package->fresh(ListDeliveryPackagesQuery::relations());
        }, attempts: config('database.transaction_attempts'));
    }
}
