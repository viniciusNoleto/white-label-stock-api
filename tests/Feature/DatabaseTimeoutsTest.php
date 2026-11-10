<?php

use Illuminate\Support\Facades\DB;

/*
| Timeouts do Postgres (`config/database.php` › `server_options`): sem eles, um lock esquecido prende
| o worker do php-fpm até o `max_execution_time` e as requisições seguintes fazem fila atrás dele.
*/

it('sends lock_timeout and statement_timeout on every connection', function () {
    expect(DB::scalar('SHOW lock_timeout'))->toBe('5s')
        ->and(DB::scalar('SHOW statement_timeout'))->toBe('30s');
});
