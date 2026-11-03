<?php

use App\Actions\Auth\CreateAccessTokenAction;
use App\Models\User;
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
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

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
