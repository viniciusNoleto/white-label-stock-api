<?php

namespace App\Actions\Delivery\DeliveryPackage;

use App\Models\Delivery\DeliveryPackage;

final class DeleteDeliveryPackageAction {

    /**
     * Remove o pacote e seus itens (cascade). Não mexe no estoque: pendente nunca subtraiu nada,
     * e excluir um entregue só apaga o histórico (os itens não voltam).
     *
     * @param mixed $id
     * @return void
     */
    public function handle(mixed $id): void {
        DeliveryPackage::findOrFailByKey($id, 'messages.delivery_packages.not_found')->delete();
    }
}
