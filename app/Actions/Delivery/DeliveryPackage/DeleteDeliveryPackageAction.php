<?php

namespace App\Actions\Delivery\DeliveryPackage;

use App\Exceptions\ApiException;
use App\Models\Delivery\DeliveryPackage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeleteDeliveryPackageAction {

    private const NOT_FOUND = 'messages.delivery_packages.not_found';

    /**
     * Só pacote **pendente** pode ser excluído: desfaz a reserva (as linhas saem em cascata) e o
     * estoque fica como estava, porque pendente nunca subtraiu nada.
     * Pacote entregue é o registro da venda/saída: excluí-lo apagaria a prova do que saiu (as
     * movimentações perderiam o vínculo com o pacote) sem devolver nada ao estoque → 422.
     * A linha fica travada: não dá para excluir enquanto outra requisição entrega o mesmo pacote.
     *
     * @param mixed $id
     * @return void
     * @throws Throwable
     */
    public function handle(mixed $id): void {
        $package_id = parse_id($id) ?? throw new ModelNotFoundException(self::NOT_FOUND);

        DB::transaction(function () use ($package_id) {
            $package = DeliveryPackage::query()->lockForUpdate()->find($package_id)
                ?? throw new ModelNotFoundException(self::NOT_FOUND);

            if ($package->isDelivered()) {
                throw new ApiException('messages.delivery_packages.delivered_locked');
            }

            $package->delete();
        }, attempts: config('database.transaction_attempts'));
    }
}
