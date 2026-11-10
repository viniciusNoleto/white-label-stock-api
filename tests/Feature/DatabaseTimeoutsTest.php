<?php

use Illuminate\Support\Facades\DB;

/*
| Timeouts do Postgres (`config/database.php` › `server_options`): sem eles, um lock esquecido prende
| o worker do php-fpm até o `max_execution_time` e as requisições seguintes fazem fila atrás dele.
|
| O teste não depende do `.env` de quem roda: abre uma conexão própria com valores escolhidos aqui
| (a conexão do teste está dentro da transação do `RefreshDatabase` e não pode ser recriada).
*/

it('sends the configured lock_timeout and statement_timeout on every connection', function () {
    config(['database.connections.timeouts_check' => [
        ...config('database.connections.pgsql'),
        'server_options' => ['lock_timeout' => '7s', 'statement_timeout' => '45s'],
    ]]);

    try {
        $connection = DB::connection('timeouts_check');

        expect($connection->scalar('SHOW lock_timeout'))->toBe('7s')
            ->and($connection->scalar('SHOW statement_timeout'))->toBe('45s');
    } finally {
        DB::purge('timeouts_check');
    }
});

it('has timeouts on the application connection', function () {
    // Qualquer valor diferente de `0` (desligado): o valor exato é do ambiente.
    expect(DB::scalar('SHOW lock_timeout'))->not->toBe('0')
        ->and(DB::scalar('SHOW statement_timeout'))->not->toBe('0');
});
