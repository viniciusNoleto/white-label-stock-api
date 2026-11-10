<?php

namespace App\Enum\Inventory;

/**
 * Tipo de movimentação de um item do estoque (`stock_movements.type`).
 */
enum StockMovementTypeEnum: string {

    /** Itens gerados sem consumir receita (entrada no estoque). */
    case ENTRY = 'entry';

    /** Itens gerados consumindo a receita. */
    case PRODUCTION = 'production';

    /** Componente gasto numa produção. */
    case CONSUMPTION = 'consumption';

    /** Quantidade de um lote a granel alterada à mão (definir, somar ou subtrair). */
    case ADJUSTMENT = 'adjustment';

    /** Item mudou de armazenamento. */
    case TRANSFER = 'transfer';

    /** Saída por pacote entregue. */
    case DELIVERY = 'delivery';

    /** Baixa manual: item excluído (perda, vencido, quebrado). */
    case WRITE_OFF = 'write_off';
}
