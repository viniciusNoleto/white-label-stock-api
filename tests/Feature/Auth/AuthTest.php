<?php

use App\Actions\Auth\CreateRefreshTokenAction;
use App\Actions\Auth\RefreshSessionAction;
use App\Actions\Auth\RotateRefreshTokenAction;
use App\Models\Auth\AccessToken;
use App\Models\Auth\RefreshToken;
use App\Models\Auth\RegistrationRequest;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function login(string $email = 'ana@example.com', string $password = 'secret123'): TestResponse {
    return test()->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
}

/**
 * O login precisa gastar exatamente um `Hash::check` em qualquer caminho (o custo do bcrypt é o que
 * o tempo de resposta mede). O `make` só aparece para gerar o hash descartável.
 */
function expectOneBcryptCheck(): void {
    Hash::shouldReceive('make')->andReturn('$2y$04$' . str_repeat('a', 53));
    Hash::shouldReceive('check')->once()->andReturnFalse();
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

    it('spends exactly one bcrypt check whether or not the e-mail exists', function (bool $exists) {
        if ($exists) {
            User::factory()->create(['email' => 'ana@example.com']);
        }

        // Sem o hash descartável, e-mail inexistente respondia sem bcrypt: mais rápido, e dava para
        // descobrir quais e-mails têm conta só medindo o tempo.
        expectOneBcryptCheck();

        login('ana@example.com', 'wrong-password')->assertUnauthorized();
    })->with(['existing e-mail' => true, 'unknown e-mail' => false]);

    it('spends exactly one bcrypt check for a pending registration', function () {
        RegistrationRequest::factory()->create(['email' => 'ana@example.com']);
        expectOneBcryptCheck();

        login('ana@example.com', 'wrong-password')->assertUnauthorized();
    });

    it('limits attempts per e-mail and answers 429 in the API envelope', function () {
        User::factory()->create(['email' => 'ana@example.com']);

        foreach (range(1, 5) as $_) {
            login('ana@example.com', 'wrong-password')->assertUnauthorized();
        }

        login('ana@example.com', 'wrong-password')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.en-us', 'Too many attempts. Wait a moment and try again.');

        // Outra conta, do mesmo IP, ainda pode tentar (até o limite por IP).
        login('bia@example.com', 'wrong-password')->assertUnauthorized();
    });

    it('limits attempts per client IP across e-mails, reading the IP forwarded by the private proxy', function () {
        $from = fn(string $ip) => $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])->withHeader('X-Forwarded-For', $ip);

        foreach (range(1, 20) as $i) {
            $from('203.0.113.7')->postJson('/api/auth/login', ['email' => "user{$i}@example.com", 'password' => 'x'])->assertUnauthorized();
        }

        $from('203.0.113.7')->postJson('/api/auth/login', ['email' => 'another@example.com', 'password' => 'x'])->assertTooManyRequests();
        // Outro cliente atrás do mesmo proxy (o web) tem a própria cota.
        $from('203.0.113.8')->postJson('/api/auth/login', ['email' => 'another@example.com', 'password' => 'x'])->assertUnauthorized();
    });

    it('does not trust X-Forwarded-For from a public address', function () {
        $from = fn(string $ip) => $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeader('X-Forwarded-For', $ip);

        foreach (range(1, 20) as $i) {
            $from("203.0.113.{$i}")->postJson('/api/auth/login', ['email' => "user{$i}@example.com", 'password' => 'x'])->assertUnauthorized();
        }

        // Trocar o X-Forwarded-For não dribla o limite: o IP considerado continua sendo 198.51.100.1.
        $from('203.0.113.99')->postJson('/api/auth/login', ['email' => 'last@example.com', 'password' => 'x'])->assertTooManyRequests();
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

    it('answers unknown routes with 404 even without a session', function () {
        $this->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('data', null);
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

    it('limits refreshes per token', function () {
        $user = User::factory()->create();
        $token = app(CreateRefreshTokenAction::class)->handle($user->id)->token;

        foreach (range(1, 10) as $_) {
            $this->postJson('/api/auth/token/refresh', ['refresh_token' => $token])->assertOk();
        }

        $this->postJson('/api/auth/token/refresh', ['refresh_token' => $token])->assertTooManyRequests();
    });

    it('validates the payload', function () {
        $this->postJson('/api/auth/token/refresh')
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Invalid data.');
    });

    // Sequencial: prova a janela de reuso. A corrida de verdade (processos paralelos, cache em banco)
    // está em `tests/Concurrency/RefreshSessionConcurrencyTest.php`.
    it('gives repeated refreshes with the same token within the window the same new session', function () {
        $user = User::factory()->create();
        $token = app(CreateRefreshTokenAction::class)->handle($user->id)->token;

        $a = $this->postJson('/api/auth/token/refresh', ['refresh_token' => $token])->assertOk()->json('data');
        $b = $this->postJson('/api/auth/token/refresh', ['refresh_token' => $token])->assertOk()->json('data');

        expect($a['refresh_token'])->toBe($b['refresh_token'])
            ->and($a['access_token'])->toBe($b['access_token'])
            ->and(RefreshToken::where('revoked', false)->count())->toBe(1);
    });

    it('revokes the whole family when an already rotated token is reused', function () {
        Log::spy();
        $user = User::factory()->create();
        $original = app(CreateRefreshTokenAction::class)->handle($user->id)->token;
        $rotate = app(RotateRefreshTokenAction::class);

        $rotated = $rotate->handle($original);
        expect($rotated)->not->toBeNull();

        // alguém reapresenta o token antigo (já trocado): sinal de roubo
        expect($rotate->handle($original))->toBeNull();

        // até o token "legítimo" mais novo deixa de funcionar
        expect($rotate->handle($rotated[1]->token))->toBeNull();

        Log::shouldHaveReceived('warning')->with('auth.refresh_token_reused', Mockery::on(fn(array $context) => $context['user_id'] === $user->id));
    });

    it('also kills the access tokens of a family whose refresh token was reused', function () {
        User::factory()->create(['email' => 'ana@example.com']);
        $stolen = login()->json('data');
        $other_device = login()->json('data');

        // O ladrão rotaciona primeiro; o dono reapresenta o token antigo e dispara a detecção de reuso.
        $thief = $this->postJson('/api/auth/token/refresh', ['refresh_token' => $stolen['refresh_token']])->json('data');
        $this->travel(11)->seconds(); // fora da janela de dedupe
        $this->postJson('/api/auth/token/refresh', ['refresh_token' => $stolen['refresh_token']])->assertUnauthorized();

        // Os access tokens da família (o do login e o do ladrão) caem na hora; o outro aparelho segue.
        $this->withToken($stolen['access_token'])->getJson('/api/auth/me')->assertUnauthorized();
        $this->withToken($thief['access_token'])->getJson('/api/auth/me')->assertUnauthorized();
        $this->withToken($other_device['access_token'])->getJson('/api/auth/me')->assertOk();
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

describe('auth:prune-tokens', function () {
    it('deletes expired tokens and keeps revoked refresh tokens until they expire (reuse detection)', function () {
        $user = User::factory()->create();
        $live_access = accessToken($user);
        AccessToken::create(['user_id' => $user->id, 'token_hash' => hash_token('old-access'), 'expires_at' => now()->subMinute()]);
        RefreshToken::create(['user_id' => $user->id, 'token_hash' => hash_token('old-refresh'), 'family_id' => (string)Str::uuid(), 'expires_at' => now()->subDay()]);
        RefreshToken::create(['user_id' => $user->id, 'token_hash' => hash_token('rotated'), 'family_id' => (string)Str::uuid(), 'revoked' => true, 'expires_at' => now()->addDay()]);

        $this->artisan('auth:prune-tokens')->assertSuccessful();

        expect(AccessToken::pluck('token_hash')->all())->toBe([hash_token($live_access)])
            ->and(RefreshToken::pluck('token_hash')->all())->toBe([hash_token('rotated')]);
    });

    it('is scheduled daily', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn($event) => str_contains($event->command, 'auth:prune-tokens'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('30 3 * * *');
    });
});

describe('operations', function () {
    it('returns a request id on every response, reusing a valid incoming one', function () {
        $this->getJson('/api/units')->assertUnauthorized()->assertHeader('X-Request-Id');

        $this->withHeader('X-Request-Id', 'web-1234-abcd')->getJson('/api/units')->assertHeader('X-Request-Id', 'web-1234-abcd');

        $generated = $this->withHeader('X-Request-Id', 'bad id with spaces')->getJson('/api/units')->headers->get('X-Request-Id');
        expect($generated)->not->toBe('bad id with spaces')->toMatch('/^[0-9a-f-]{36}$/');
    });

    it('checks the database in the health endpoint', function () {
        $this->get('/up')->assertOk();

        DB::partialMock()->shouldReceive('select')->with('SELECT 1')->andThrow(new RuntimeException('database down'));

        $this->get('/up')->assertStatus(500);
    });
});

describe('PUT /api/auth/password', function () {
    it('changes the password, signs out every other session and returns a new one', function () {
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => 'secret123']);
        $current = login()->json('data');
        $other_device = login()->json('data');

        $response = $this->withToken($current['access_token'])
            ->putJson('/api/auth/password', ['current_password' => 'secret123', 'password' => 'new-secret-456'])
            ->assertOk()
            ->assertJsonPath('message.en-us', 'Password changed. Your other sessions were signed out.')
            ->assertJsonPath('data.user.id', $user->id);

        expect(Hash::check('new-secret-456', $user->fresh()->password))->toBeTrue();

        $this->withToken($other_device['access_token'])->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/token/refresh', ['refresh_token' => $other_device['refresh_token']])->assertUnauthorized();
        $this->withToken($response->json('data.access_token'))->getJson('/api/auth/me')->assertOk();
        login(password: 'secret123')->assertUnauthorized();
        login(password: 'new-secret-456')->assertOk();
    });

    it('rejects a wrong current password without changing anything', function () {
        $user = User::factory()->create(['password' => 'secret123']);

        $this->withToken(accessToken($user))
            ->putJson('/api/auth/password', ['current_password' => 'wrong', 'password' => 'new-secret-456'])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'The current password is incorrect.');

        expect(Hash::check('secret123', $user->fresh()->password))->toBeTrue();
    });

    it('validates the payload', function (array $payload, string $message) {
        $this->withToken(accessToken())
            ->putJson('/api/auth/password', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', $message);
    })->with([
        [[], 'Current and new password are required.'],
        [['current_password' => 'secret123', 'password' => 'short'], 'Password must be at least 8 characters.'],
    ]);

    it('requires a session', function () {
        $this->putJson('/api/auth/password', ['current_password' => 'a', 'password' => 'b'])->assertUnauthorized();
    });

    it('limits attempts to guess the current password', function () {
        $this->withToken(accessToken());

        foreach (range(1, 5) as $_) {
            $this->putJson('/api/auth/password', ['current_password' => 'wrong', 'password' => 'new-secret-456'])->assertUnprocessable();
        }

        $this->putJson('/api/auth/password', ['current_password' => 'wrong', 'password' => 'new-secret-456'])->assertTooManyRequests();
    });
});

describe('user:reset-password', function () {
    it('lets the server operator reset any password, admins included, and signs the user out', function () {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
        $token = accessToken($admin);

        $this->artisan('user:reset-password', ['email' => 'ADMIN@example.com'])
            ->expectsQuestion('Nova senha (mínimo 8 caracteres)', 'recovered-123')
            ->expectsQuestion('Repita a nova senha', 'recovered-123')
            ->assertSuccessful();

        expect(Hash::check('recovered-123', $admin->fresh()->password))->toBeTrue();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    });

    it('changes nothing when the confirmation does not match', function () {
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => 'secret123']);

        $this->artisan('user:reset-password', ['email' => 'ana@example.com'])
            ->expectsQuestion('Nova senha (mínimo 8 caracteres)', 'recovered-123')
            ->expectsQuestion('Repita a nova senha', 'other-123')
            ->assertFailed();

        expect(Hash::check('secret123', $user->fresh()->password))->toBeTrue();
    });
});
