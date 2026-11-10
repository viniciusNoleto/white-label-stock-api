<?php

namespace App\Enum\Inventory;

/**
 * Motivo de uma baixa manual (`stock_movements.reason`, só em `write_off`). Separa a perda por
 * vencimento das outras perdas: é o número que o dono quer ver no fim do mês.
 */
enum WriteOffReasonEnum: string {

    /** Passou da validade. */
    case EXPIRED = 'expired';

    /** Quebrou, estragou, ficou fora do padrão. */
    case DAMAGED = 'damaged';

    /** Sumiu: furto, extravio, erro de contagem. */
    case LOST = 'lost';

    /** Qualquer outro motivo. */
    case OTHER = 'other';
}
