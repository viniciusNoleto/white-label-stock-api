<?php

namespace App\Base\Database;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use PDOException;
use Throwable;

/**
 * Decide quais erros o `DB::transaction(…, attempts)` repete. Substitui o detector padrão do Laravel,
 * que reconhece deadlock pelo **texto** da mensagem ("deadlock detected"): com o Postgres em outro
 * idioma (`lc_messages = 'pt_BR'`) a mensagem vem traduzida e o retry deixava de acontecer em silêncio.
 * Aqui a decisão é pelo SQLSTATE, que não muda com o idioma.
 *
 * Repete: 40P01 (deadlock) e 40001 (falha de serialização). A transação perdedora foi desfeita
 * inteira pelo banco, então repetir é seguro e quase sempre resolve.
 *
 * **Não** repete 55P03 (lock não obtido em `lock_timeout`): a transação já esperou 5 s pelo lock;
 * repetir faria o cliente esperar até 15 s por um lock que provavelmente continua preso. Sai direto
 * como 409 "tente de novo" (`Response::returnError`), e o cliente decide.
 */
final class ConcurrencyErrorDetector implements ConcurrencyErrorDetectorContract {

    /** SQLSTATEs repetidos pela transação (os demais erros sobem na primeira tentativa). */
    public const RETRYABLE_SQLSTATES = ['40P01', '40001'];

    /**
     * @param Throwable $e
     * @return bool
     */
    public function causedByConcurrencyError(Throwable $e): bool {
        // `QueryException` estende `PDOException` e carrega o SQLSTATE em `getCode()`.
        return $e instanceof PDOException && in_array((string)$e->getCode(), self::RETRYABLE_SQLSTATES, true);
    }
}
