<?php

namespace App\Enum\Delivery;

/**
 * Pendente: itens separados (contam como "empacotados" no estoque, ainda não saíram).
 * Entregue: itens já subtraídos do estoque; o pacote não pode mais ser editado.
 */
enum DeliveryPackageStatusEnum: string {
    case PENDING = 'pending';
    case DELIVERED = 'delivered';
}
