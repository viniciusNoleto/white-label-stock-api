<?php

use App\Models\User;
use App\Services\Auth\CreateAccessTokenService;
use App\Services\Auth\CreateRefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * Cookies de uma sessão válida (access + refresh) para o usuário informado ou um novo.
 *
 * @param User|null $user
 * @return array{access_token: string, refresh_token: string}
 */
function sessionCookies(?User $user = null): array {
    $user ??= User::factory()->create();

    return [
        'access_token' => app(CreateAccessTokenService::class)->execute($user->id)->token,
        'refresh_token' => app(CreateRefreshTokenService::class)->execute($user->id)->token,
    ];
}
