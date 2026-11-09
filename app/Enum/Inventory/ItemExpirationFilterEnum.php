<?php

namespace App\Enum\Inventory;

/**
 * Filtro de validade da listagem de itens.
 */
enum ItemExpirationFilterEnum: string {

    /** Vencimento antes de hoje. */
    case EXPIRED = 'expired';

    /** Vence de hoje até `Item::EXPIRING_DAYS` dias. */
    case EXPIRING = 'expiring';

    /** Sem validade ou vencimento a partir de hoje. */
    case VALID = 'valid';
}
