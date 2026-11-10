<?php

use App\Actions\Auth\CreateAccessTokenAction;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature: requisições reais contra o banco `${DB_DATABASE}_testing` (Postgres),
| recriado pelas migrations e isolado em transação a cada teste.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Garantia dinâmica da auditoria: depois de cada teste, o estado dos itens precisa bater com o
    // ledger de movimentações. Mutação de estoque sem movimentação quebra aqui, venha de onde vier.
    ->afterEach(fn() => expect(stockLedgerViolations())->toBe([]))
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

// Concorrência: processos de verdade (pcntl_fork), cada um com a própria conexão. Os dados precisam
// estar commitados para os filhos enxergarem, então aqui é truncamento, não transação.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Access token válido (para `withToken`) do usuário informado ou de um novo.
 *
 * @param User|null $user
 * @return string
 */
function accessToken(?User $user = null): string {
    $user ??= User::factory()->create();

    return app(CreateAccessTokenAction::class)->handle($user->id)->token;
}

/**
 * Simula falha de banco (o endpoint deve responder 500 com a mensagem `…_error`): renomeia a
 * tabela dentro da transação do teste (o `RefreshDatabase` desfaz no rollback).
 *
 * @param string $table
 * @return void
 */
function failTable(string $table): void {
    DB::statement("ALTER TABLE {$table} RENAME TO {$table}_unavailable");
}

/**
 * Roda `$work($worker)` em `$workers` processos paralelos (fork), todos largando no mesmo instante, e
 * devolve o resultado de cada um: `['ok' => true, 'value' => …]` ou
 * `['ok' => false, 'error' => classe, 'key' => chave da ApiException|null, 'sqlstate' => …]`.
 * O pai fecha a conexão antes do fork: cada filho abre a sua (conexão PDO não pode ser compartilhada).
 * Filho que não termina em `$timeout_seconds` (lock preso, laço infinito) é morto com SIGKILL e volta
 * como `['ok' => false, 'error' => 'timeout']`: o teste falha em vez de pendurar a suíte e o CI.
 *
 * @param int $workers
 * @param Closure(int): mixed $work
 * @param int $timeout_seconds
 * @return array<int, array<string, mixed>>
 */
function runConcurrently(int $workers, Closure $work, int $timeout_seconds = 30): array {
    DB::disconnect();

    $dir = sys_get_temp_dir() . '/wls-concurrency-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $start_at = microtime(true) + 0.5;
    $pids = [];

    foreach (range(0, $workers - 1) as $worker) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            try {
                time_sleep_until($start_at);
                $result = ['ok' => true, 'value' => $work($worker)];
            } catch (Throwable $exception) {
                $result = [
                    'ok' => false,
                    'error' => $exception::class,
                    'key' => $exception instanceof ApiException ? $exception->message_key : null,
                    'sqlstate' => (string)$exception->getCode(),
                    'message' => $exception->getMessage(),
                ];
            }

            file_put_contents("{$dir}/{$worker}", serialize($result));
            DB::disconnect();
            // Sai sem rodar shutdown do PHPUnit/Laravel do processo pai.
            posix_kill(getmypid(), SIGKILL);
        }

        $pids[$worker] = $pid;
    }

    $deadline = $start_at + $timeout_seconds;
    $timed_out = [];

    while ($pids !== []) {
        foreach ($pids as $worker => $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                unset($pids[$worker]);
            }
        }

        if ($pids !== [] && microtime(true) > $deadline) {
            foreach ($pids as $worker => $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                $timed_out[] = $worker;
            }

            $pids = [];
        }

        usleep(10_000);
    }

    $results = [];

    foreach (range(0, $workers - 1) as $worker) {
        $results[$worker] = in_array($worker, $timed_out, true)
            ? ['ok' => false, 'error' => 'timeout']
            : (unserialize((string)@file_get_contents("{$dir}/{$worker}")) ?: ['ok' => false, 'error' => 'no result']);
        @unlink("{$dir}/{$worker}");
    }

    @rmdir($dir);

    return $results;
}

/**
 * Roda `$callback` com uma tentativa só por transação (`database.transaction_attempts = 1`): um deadlock
 * deixa de ser absorvido pelo retry e aparece no resultado do processo (`sqlstate` `40P01`). É assim que os
 * testes de concorrência provam "não há ciclo de locks", sem depender das estatísticas do Postgres.
 *
 * @template T
 * @param Closure(): T $callback
 * @return T
 */
function withoutTransactionRetries(Closure $callback): mixed {
    $attempts = config('database.transaction_attempts');
    config(['database.transaction_attempts' => 1]);

    try {
        return $callback();
    } finally {
        config(['database.transaction_attempts' => $attempts]);
    }
}

/**
 * Itens cujo estado não bate com o ledger (`stock_movements`). Para cada item com movimentação:
 * - a última movimentação deixou o saldo (`balance`) e o armazenamento que o item tem agora;
 * - se o item nasceu com movimentação (entrada/produção), a soma das quantidades com sinal é o saldo.
 * Itens criados por factory (sem movimentação) ficam de fora até a primeira movimentação.
 * Banco indisponível de propósito (`failTable`, transação abortada, `DB` mockado) → nada a conferir; o
 * teste "detects stock changed without a movement" (StockMovementTest) prova que a conferência funciona.
 *
 * @return array<int, array<string, mixed>>
 */
function stockLedgerViolations(): array {
    try {
        if (DB::scalar("SELECT to_regclass('items') IS NULL OR to_regclass('stock_movements') IS NULL")) {
            return [];
        }

        $rows = DB::select(<<<'SQL'
            WITH ordered AS (
                SELECT item_id, type, quantity, balance, storage_id,
                       ROW_NUMBER() OVER (PARTITION BY item_id ORDER BY id DESC) AS from_last,
                       FIRST_VALUE(type) OVER (PARTITION BY item_id ORDER BY id) AS first_type,
                       SUM(quantity) OVER (PARTITION BY item_id) AS total
                FROM stock_movements
                WHERE item_id IS NOT NULL
            )
            SELECT items.code, items.quantity, items.storage_id,
                   ordered.balance AS ledger_balance, ordered.storage_id AS ledger_storage_id,
                   ordered.first_type, ordered.total AS ledger_total
            FROM items
            JOIN ordered ON ordered.item_id = items.id AND ordered.from_last = 1
            WHERE ordered.balance <> items.quantity
               OR ordered.storage_id IS DISTINCT FROM items.storage_id
               OR (ordered.first_type IN ('entry', 'production') AND ordered.total <> items.quantity)
            ORDER BY items.code
            SQL);
    } catch (Throwable) {
        return [];
    }

    return array_map(fn(object $row) => (array)$row, $rows);
}
