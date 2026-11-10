<?php

use App\Actions\Auth\CreateRefreshTokenAction;
use App\Actions\Auth\RefreshSessionAction;
use App\Models\Auth\RefreshToken;
use App\Models\User;

it('gives refreshes racing with the same token the same new pair, with the production cache store', function () {
    // Como em produção: lock e resultado no cache `database`, compartilhado entre processos
    // (o `array` dos testes de feature é por processo e não prova nada aqui).
    config(['cache.default' => 'database']);

    $user = User::factory()->create();
    $issued = app(CreateRefreshTokenAction::class)->handle($user->id);

    $results = runConcurrently(6, function () use ($issued) {
        $session = app(RefreshSessionAction::class)->handle($issued->token);

        return [$session?->refresh_token->token, $session?->access_token->token];
    });

    $pairs = collect($results)->pluck('value');

    expect(collect($results)->where('ok', false)->all())->toBe([])
        ->and($pairs->filter(fn($pair) => $pair[0] === null))->toBeEmpty()
        ->and($pairs->unique(fn($pair) => implode('|', $pair)))->toHaveCount(1)
        // Uma rotação só: a família não foi revogada (sem logout forçado) e há um único token vivo.
        ->and(RefreshToken::where('family_id', $issued->family_id)->where('revoked', false)->count())->toBe(1)
        ->and(RefreshToken::where('family_id', $issued->family_id)->count())->toBe(2);
});
