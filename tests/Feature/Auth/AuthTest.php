<?php

use App\Models\Auth\AccessToken;
use App\Models\Auth\RefreshToken;
use App\Models\User;
use App\Services\Auth\CreateRefreshTokenService;
use App\Services\Auth\RefreshSessionService;
use App\Services\Auth\RotateRefreshTokenService;
use Illuminate\Testing\TestResponse;

function login(string $email = 'ana@example.com', string $password = 'secret123'): TestResponse {
    return test()->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
}

function cookieValue(TestResponse $response, string $name): ?string {
    return $response->getCookie($name, decrypt: false)?->getValue();
}

function cookiesOf(TestResponse $response): array {
    return [
        'access_token' => cookieValue($response, 'access_token'),
        'refresh_token' => cookieValue($response, 'refresh_token'),
    ];
}

describe('POST /api/auth/login', function () {
    it('signs in and sets both session cookies as httpOnly', function () {
        $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);

        $response = login('  ANA@example.com ');

        $response->assertOk()
            ->assertJsonPath('data', ['user' => ['id' => $user->id, 'name' => 'Ana', 'email' => 'ana@example.com']])
            ->assertJsonPath('message.pt-br', 'Login realizado com sucesso.');

        foreach (['access_token', 'refresh_token'] as $name) {
            $cookie = $response->getCookie($name, decrypt: false);

            expect($cookie->isHttpOnly())->toBeTrue()
                ->and($cookie->getSameSite())->toBe('lax')
                ->and($cookie->getPath())->toBe('/');
        }

        // Só o hash vai para o banco — nunca o token cru.
        $raw = cookieValue($response, 'access_token');
        expect(AccessToken::where('token_hash', hash_token($raw))->exists())->toBeTrue()
            ->and(AccessToken::where('token_hash', $raw)->exists())->toBeFalse();
    });

    it('rejects wrong credentials with the same message for unknown e-mails', function () {
        User::factory()->create(['email' => 'ana@example.com']);

        $wrong_password = login('ana@example.com', 'nope');
        $unknown_email = login('ghost@example.com');

        $wrong_password->assertUnauthorized();
        $unknown_email->assertUnauthorized();

        expect($wrong_password->json('message'))->toBe($unknown_email->json('message'))
            ->and($wrong_password->json('message.pt-br'))->toBe('E-mail ou senha inválidos.')
            ->and($wrong_password->headers->getCookies())->toBeEmpty();
    });

    it('validates the payload', function () {
        $this->postJson('/api/auth/login', ['email' => 'a@b.c'])
            ->assertUnprocessable()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.en-us', 'Invalid data.');
    });
});

describe('GET /api/auth/me', function () {
    it('returns null for anonymous visitors without triggering a refresh', function () {
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data', ['user' => null]);
    });

    it('returns 401 when only the refresh token is left (client should refresh)', function () {
        $this->withUnencryptedCookies(['refresh_token' => 'whatever'])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    });

    it('returns the logged user', function () {
        User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $cookies = cookiesOf(login());

        $this->withUnencryptedCookies($cookies)
            ->getJson('/api/auth/me')
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
    })->with(['/api/units', '/api/item-categories', '/api/storages', '/api/inventory-items']);

    it('rejects an expired access token', function () {
        User::factory()->create(['email' => 'ana@example.com']);
        $cookies = cookiesOf(login());
        AccessToken::query()->update(['expires_at' => now()->subSecond()]);

        $this->withUnencryptedCookies($cookies)->getJson('/api/units')->assertUnauthorized();
    });

    it('answers unknown routes with 404 in the API envelope', function () {
        $this->withUnencryptedCookies(sessionCookies())
            ->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message.en-us', 'Record not found.');
    });
});

describe('POST /api/auth/token/refresh', function () {
    it('rotates the refresh token and issues a new access token', function () {
        User::factory()->create(['email' => 'ana@example.com']);
        $first = cookiesOf(login());

        $refreshed = $this->withUnencryptedCookies($first)->postJson('/api/auth/token/refresh');

        $refreshed->assertOk();
        expect(cookieValue($refreshed, 'refresh_token'))->not->toBe($first['refresh_token'])
            ->and(cookieValue($refreshed, 'access_token'))->not->toBe($first['access_token'])
            ->and(RefreshToken::byToken($first['refresh_token'])->first()->revoked)->toBeTrue();
    });

    it('clears the cookies when there is no valid refresh token', function () {
        $response = $this->withUnencryptedCookies(['refresh_token' => 'invalid'])->postJson('/api/auth/token/refresh');

        $response->assertUnauthorized();

        foreach (['access_token', 'refresh_token'] as $name) {
            expect($response->getCookie($name, decrypt: false)->isCleared())->toBeTrue();
        }
    });

    it('gives concurrent refreshes with the same token the same new session', function () {
        $user = User::factory()->create();
        $token = app(CreateRefreshTokenService::class)->execute($user->id)->token;

        $a = app(RefreshSessionService::class)->execute($token);
        $b = app(RefreshSessionService::class)->execute($token);

        expect($a->refresh_token->token)->toBe($b->refresh_token->token)
            ->and($a->access_token->token)->toBe($b->access_token->token)
            ->and(RefreshToken::where('revoked', false)->count())->toBe(1);
    });

    it('revokes the whole family when an already rotated token is reused', function () {
        $user = User::factory()->create();
        $original = app(CreateRefreshTokenService::class)->execute($user->id)->token;
        $rotate = app(RotateRefreshTokenService::class);

        $rotated = $rotate->execute($original);
        expect($rotated)->not->toBeNull();

        // alguém reapresenta o token antigo (já trocado): sinal de roubo
        expect($rotate->execute($original))->toBeNull();

        // até o token "legítimo" mais novo deixa de funcionar
        expect($rotate->execute($rotated[1]->token))->toBeNull();
    });

    it('rejects expired refresh tokens', function () {
        $user = User::factory()->create();
        $token = app(CreateRefreshTokenService::class)->execute($user->id)->token;
        RefreshToken::query()->update(['expires_at' => now()->subSecond()]);

        expect(app(RotateRefreshTokenService::class)->execute($token))->toBeNull();
    });
});

describe('POST /api/auth/logout', function () {
    it('revokes both tokens and clears the cookies', function () {
        User::factory()->create(['email' => 'ana@example.com']);
        $cookies = cookiesOf(login());

        $logout = $this->withUnencryptedCookies($cookies)->postJson('/api/auth/logout');

        $logout->assertOk()->assertJsonPath('message.pt-br', 'Sessão encerrada.');
        expect($logout->getCookie('access_token', decrypt: false)->isCleared())->toBeTrue()
            ->and($logout->getCookie('refresh_token', decrypt: false)->isCleared())->toBeTrue();

        $this->withUnencryptedCookies($cookies)->getJson('/api/units')->assertUnauthorized();
        $this->withUnencryptedCookies($cookies)->postJson('/api/auth/token/refresh')->assertUnauthorized();
    });
});
