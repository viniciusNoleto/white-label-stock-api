<?php

use App\Base\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Quais erros o `DB::transaction(…, attempts)` repete. Fica em Unit de propósito: nos testes de Feature
| a transação do `RefreshDatabase` envolve tudo, e o Laravel só repete na transação de nível 1.
*/

/**
 * `QueryException` com o SQLSTATE e a mensagem que o Postgres mandaria.
 */
function queryExceptionWithSqlstate(string $sqlstate, string $message): QueryException {
    // O PDO grava o SQLSTATE (string) em `code`; o construtor de `PDOException` só aceita int.
    $pdo = new class($message, $sqlstate) extends PDOException {
        public function __construct(string $message, string $sqlstate) {
            parent::__construct($message);
            $this->code = $sqlstate;
        }
    };

    return new QueryException('pgsql', 'SELECT 1', [], $pdo);
}

/**
 * Quantas vezes a transação rodou até desistir, lançando sempre a mesma exceção.
 */
function transactionRunsUntilGivingUp(QueryException $exception, int $attempts = 3): int {
    $runs = 0;

    try {
        DB::transaction(function () use (&$runs, $exception) {
            $runs++;

            throw $exception;
        }, attempts: $attempts);
    } catch (QueryException) {
    }

    return $runs;
}

it('retries deadlocks and serialization failures by SQLSTATE, whatever the server language', function (string $sqlstate, string $message) {
    expect(transactionRunsUntilGivingUp(queryExceptionWithSqlstate($sqlstate, $message)))->toBe(3);
})->with([
    'deadlock in English' => ['40P01', 'ERROR: deadlock detected'],
    // O detector padrão do Laravel procura o texto em inglês: em português, não repetia.
    'deadlock in Portuguese' => ['40P01', 'ERRO: impasse detectado'],
    'serialization failure' => ['40001', 'ERROR: could not serialize access due to concurrent update'],
]);

it('does not retry a lock timeout (55P03): it already waited lock_timeout, so it answers 409 at once', function () {
    expect(transactionRunsUntilGivingUp(queryExceptionWithSqlstate('55P03', 'ERROR: canceling statement due to lock timeout')))->toBe(1)
        ->and(is_concurrency_failure(queryExceptionWithSqlstate('55P03', 'ERROR: canceling statement due to lock timeout')))->toBeTrue();
});

it('does not retry other database errors', function () {
    expect(transactionRunsUntilGivingUp(queryExceptionWithSqlstate('23505', 'ERROR: duplicate key value')))->toBe(1);
});

it('is the detector bound in the container', function () {
    expect(app(Illuminate\Contracts\Database\ConcurrencyErrorDetector::class))->toBeInstanceOf(ConcurrencyErrorDetector::class);
});
