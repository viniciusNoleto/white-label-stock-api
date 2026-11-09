<?php

use App\Actions\Auth\CreateRefreshTokenAction;
use App\Actions\Auth\RefreshSessionAction;
use App\Actions\Auth\RotateRefreshTokenAction;
use App\Models\Auth\AccessToken;
use App\Models\Auth\RefreshToken;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function login(string $email = 'ana@example.com', string $password = 'secret123'): TestResponse {
    return test()->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
}

describe('POST /api/auth/login', function () {
    it('signs in and returns the session tokens in the body', function () {
        $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);

        $response = login('  ANA@example.com ');

        $response->assertOk()
            ->assertJsonPath('data.user', ['id' => $user->id, 'name' => 'Ana', 'email' => 'ana@example.com', 'is_admin' => false])
            ->assertJsonPath('message.pt-br', 'Login realizado com sucesso.');

        expect(array_keys($response->json('data')))->toBe(['user', 'access_token', 'refresh_token', 'expires_in', 'refresh_expires_in'])
            ->and($response->json('data.expires_in'))->toBeGreaterThan(14 * 60)->toBeLessThanOrEqual(15 * 60)
            ->and($response->json('data.refresh_expires_in'))->toBeGreaterThan(29 * 86400)->toBeLessThanOrEqual(30 * 86400)
            ->and($response->headers->getCookies())->toBeEmpty();

        // Só o hash vai para o banco — nunca o token cru.
        $raw = $response->json('data.access_token');
        expect(AccessToken::where('token_hash', hash_token($raw))->exists())->toBeTrue()
            ->and(AccessToken::where('token_hash', $raw)->exists())->toBeFalse()
            ->and(RefreshToken::where('token_hash', hash_token($response->json('data.refresh_token')))->exists())->toBeTrue();
    });

    it('rejects wrong credentials with the same message for unknown e-mails', function () {
        User::factory()->create(['email' => 'ana@example.com']);

        $wrong_password = login('ana@example.com', 'nope');
        $unknown_email = login('ghost@example.com');

        $wrong_password->assertUnauthorized()->assertJsonPath('data', null);
        $unknown_email->assertUnauthorized();

        expect($wrong_password->json('message'))->toBe($unknown_email->json('message'))
            ->and($wrong_password->json('message.pt-br'))->toBe('E-mail ou senha inválidos.');
    });

    it('validates the payload', function () {
        $this->postJson('/api/auth/login', ['email' => 'a@b.c'])
            ->assertUnprocessable()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.en-us', 'Invalid data.');
    });
});

describe('GET /api/auth/me', function () {
    it('requires an access token', function () {
        $this->getJson('/api/auth/me')->assertUnauthorized()->assertJsonPath('data', null);
    });

    it('returns the logged user', function () {
        User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);

        $this->withToken(login()->json('data.access_token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Ana')
            ->assertJsonPath('data.user.email', 'ana@example.com');
    });
});

describe('protected API routes', function () {
    it('requires a session', function (string $path) {
        $this->getJson($path)
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'data' => null,
                'message' => api_message('messages.unauthorized'),
            ]);
    })->with(['/api/units', '/api/item-categories', '/api/storages', '/api/products', '/api/items', '/api/stock']);

    it('rejects an unknown or expired access token', function () {
        User::factory()->create(['email' => 'ana@example.com']);
        $token = login()->json('data.access_token');

        $this->withToken('not-a-token')->getJson('/api/units')->assertUnauthorized();

        AccessToken::query()->update(['expires_at' => now()->subSecond()]);
        $this->withToken($token)->getJson('/api/units')->assertUnauthorized();
    });

    it('ignores a token sent as a cookie', function () {
        $this->withUnencryptedCookies(['access_token' => accessToken()])
            ->getJson('/api/units')
            ->assertUnauthorized();
    });

    it('answers unknown routes with 404 in the API envelope', function () {
        $this->withToken(accessToken())
            ->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message.en-us', 'Record not found.');
    });
});

describe('POST /api/auth/token/refresh', function () {
    it('rotates the refresh token and issues a new access token', function () {
        User::factory()->create(['email' => 'ana@example.com']);
        $first = login()->json('data');

        $refreshed = $this->postJson('/api/auth/token/refresh', ['refresh_token' => $first['refresh_token']]);

        $refreshed->assertOk()->assertJsonPath('data.user.email', 'ana@example.com');
        expect($refreshed->json('data.refresh_token'))->not->toBe($first['refresh_token'])
            ->and($refreshed->json('data.access_token'))->not->toBe($first['access_token'])
            ->and($refreshed->json('data.expires_in'))->toBeGreaterThan(0)
            ->and(RefreshToken::byToken($first['refresh_token'])->first()->revoked)->toBeTrue();

        $this->withToken($refreshed->json('data.access_token'))->getJson('/api/units')->assertOk();
    });

    it('answers 401 for an invalid refresh token', function () {
        $this->postJson('/api/auth/token/refresh', ['refresh_token' => 'invalid'])
            ->assertUnauthorized()
            ->assertJsonPath('data', null);
    });

    it('validates the payload', function () {
        $this->postJson('/api/auth/token/refresh')
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Invalid data.');
    });

    it('gives concurrent refreshes with the same token the same new session', function () {
        $user = User::factory()->create();
        $token = app(CreateRefreshTokenAction::class)->handle($user->id)->token;

        $a = $this->postJson('/api/auth/token/refresh', ['refresh_token' => $token])->assertOk()->json('data');
        $b = $this->postJson('/api/auth/token/refresh', ['refresh_token' => $token])->assertOk()->json('data');

        expect($a['refresh_token'])->toBe($b['refresh_token'])
            ->and($a['access_token'])->toBe($b['access_token'])
            ->and(RefreshToken::where('revoked', false)->count())->toBe(1);
    });

    it('revokes the whole family when an already rotated token is reused', function () {
        $user = User::factory()->create();
        $original = app(CreateRefreshTokenAction::class)->handle($user->id)->token;
        $rotate = app(RotateRefreshTokenAction::class);

        $rotated = $rotate->handle($original);
        expect($rotated)->not->toBeNull();

        // alguém reapresenta o token antigo (já trocado): sinal de roubo
        expect($rotate->handle($original))->toBeNull();

        // até o token "legítimo" mais novo deixa de funcionar
        expect($rotate->handle($rotated[1]->token))->toBeNull();
    });

    it('rejects expired refresh tokens', function () {
        $user = User::factory()->create();
        $token = app(CreateRefreshTokenAction::class)->handle($user->id)->token;
        RefreshToken::query()->update(['expires_at' => now()->subSecond()]);

        expect(app(RefreshSessionAction::class)->handle($token))->toBeNull();
    });
});

describe('POST /api/auth/logout', function () {
    it('revokes the access token (header) and the refresh token (body)', function () {
        User::factory()->create(['email' => 'ana@example.com']);
        $session = login()->json('data');

        $this->withToken($session['access_token'])
            ->postJson('/api/auth/logout', ['refresh_token' => $session['refresh_token']])
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Sessão encerrada.')
            ->assertJsonPath('data', null);

        $this->withToken($session['access_token'])->getJson('/api/units')->assertUnauthorized();
        $this->postJson('/api/auth/token/refresh', ['refresh_token' => $session['refresh_token']])->assertUnauthorized();
    });

    it('still revokes the refresh token when the access token is already gone', function () {
        User::factory()->create(['email' => 'ana@example.com']);
        $session = login()->json('data');

        $this->postJson('/api/auth/logout', ['refresh_token' => $session['refresh_token']])->assertOk();

        $this->postJson('/api/auth/token/refresh', ['refresh_token' => $session['refresh_token']])->assertUnauthorized();
    });
});
