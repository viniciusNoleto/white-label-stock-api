<?php

/*
| Convenções do AGENTS.md que antes dependiam só de disciplina: aqui a suíte quebra se forem violadas.
*/

use App\Actions\Inventory\Item\ConsumeItemStockAction;
use App\Actions\Inventory\Product\DeleteProductAction;
use Symfony\Component\Finder\Finder;

arch('actions are final, single-purpose and HTTP-free')
    ->expect('App\Actions')
    ->classes()
    ->toBeFinal()
    ->toHaveSuffix('Action')
    ->not->toUse(['Illuminate\Http\Request', 'Illuminate\Http\JsonResponse', 'App\Base\Traits\Response']);

arch('queries are final')
    ->expect('App\Queries')
    ->classes()
    ->toBeFinal()
    ->toHaveSuffix('Query');

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('env() only inside config')
    ->expect('env')
    ->not->toBeUsedIn('App');

/*
 * Toda alteração de estoque grava movimentação (AGENTS.md › Movimentações). Isto é uma checagem
 * **estática e heurística**: lê o código das Actions e falha se uma que escreve em `items` (ou consome
 * estoque) não **chama** `$this->recordMovements->handle(`. Pega o esquecimento comum num endpoint novo,
 * mas não prova que a chamada cobre todos os caminhos. A garantia de comportamento é dinâmica: depois de
 * cada teste de Feature, `stockLedgerViolations()` (tests/Pest.php) confere o estado dos itens contra o ledger.
 */
it('makes every action that writes items call the movement recorder', function () {
    $writes_items = '/Item::query\(\)[^;]*->(insert|update|upsert|delete|decrement|increment)\(|\$item->(update|delete|fill|save|touch)\(|'
        . preg_quote(class_basename(ConsumeItemStockAction::class)) . ' \$/s';
    $records = '$this->recordMovements->handle(';
    // Isenções explícitas, cada uma com o motivo.
    $exempt = [
        class_basename(ConsumeItemStockAction::class) . '.php' => 'quem chama registra antes de consumir (documentado nela)',
        class_basename(DeleteProductAction::class) . '.php' => 'só apaga lotes zerados: o saldo não muda',
    ];
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path('Actions'))->name('*.php') as $file) {
        $source = $file->getContents();

        if (isset($exempt[$file->getFilename()]) || !preg_match($writes_items, $source)) {
            continue;
        }

        if (!str_contains($source, $records)) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('never writes items through the raw query builder, which would bypass the actions', function () {
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match("/DB::table\(\s*'items'\s*\)[^;]*->(insert|update|upsert|delete|decrement|increment)\(/s", $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('retries every action transaction on deadlock (attempts from config)', function () {
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path('Actions'))->name('*.php') as $file) {
        $source = $file->getContents();

        if (substr_count($source, 'DB::transaction(') !== substr_count($source, "attempts: config('database.transaction_attempts')")) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('does every bcmath operation at the column scale (DECIMAL_SCALE), never a hardcoded one', function () {
    // `bcadd($a, $b, 4)` espalhado é como o `SCALE = 4` repetido em seis classes: trocar a escala da
    // coluna exigiria caçar cada um. A escala vem de um lugar só (`app/Base/Helpers/numbers.php`).
    // Escala `0` é permitida: é truncar para inteiro de propósito (`is_whole_number`), não a escala da coluna.
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match('/\bbc(add|sub|mul|div|comp|mod)\([^;]*,\s*[1-9]\d*\s*\)/', $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('never locks a product row with FOR UPDATE (deadlock with the FOR KEY SHARE of every foreign key)', function () {
    // `Product::lockedForWrite()` (FOR NO KEY UPDATE) é o único lock de escrita na linha do produto
    // (AGENTS.md › Locks). Heurística: `Product::query()…->lockForUpdate()` na mesma instrução.
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match('/Product::query\(\)[^;]*->lockForUpdate\(\)/s', $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
