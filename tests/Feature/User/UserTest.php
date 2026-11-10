<?php

use App\Models\Auth\AccessToken;
use App\Models\Auth\RefreshToken;
use App\Actions\Auth\CreateRefreshTokenAction;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Zeca Admin']);
    $this->withToken(accessToken($this->admin));
});

describe('admin only', function () {
    it('returns 403 for a regular user', function (string $method, string $url) {
        $this->withToken(accessToken(User::factory()->create()))
            ->json($method, $url)
            ->assertForbidden()
            ->assertJsonPath('data', null);
    })->with([
        ['GET', '/api/users'],
        ['DELETE', '/api/users/1'],
    ]);
});

describe('GET /api/users', function () {
    it('lists admins first, then by name, paginated', function () {
        User::factory()->create(['name' => 'Bruno']);
        User::factory()->create(['name' => 'Ana']);

        $response = $this->getJson('/api/users');

        $response->assertOk()
            ->assertJsonPath('data.meta', ['page' => 1, 'per_page' => 8, 'total' => 3, 'last_page' => 1]);

        expect(collect($response->json('data.items'))->pluck('name')->all())->toBe(['Zeca Admin', 'Ana', 'Bruno'])
            ->and($response->json('data.items.0.is_admin'))->toBeTrue()
            ->and(array_keys($response->json('data.items.0')))->toBe(['id', 'name', 'email', 'is_admin', 'created_at', 'updated_at']);
    });

    it('searches by name or email', function () {
        User::factory()->create(['name' => 'Bruno', 'email' => 'bruno@loja.com']);

        expect($this->getJson('/api/users?search=loja')->json('data.items.0.name'))->toBe('Bruno')
            ->and($this->getJson('/api/users?search=loja')->json('data.meta.total'))->toBe(1);
    });

    it('returns 500 when the database fails', function () {
        failTable('users');

        $this->getJson('/api/users')->assertStatus(500);
    });
});

describe('DELETE /api/users/{id}', function () {
    it('deletes a regular user and revokes their sessions', function () {
        $user = User::factory()->create();
        $token = accessToken($user);
        app(CreateRefreshTokenAction::class)->handle($user->id);

        $this->deleteJson("/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.pt-br', 'Usuário excluído. O acesso dele foi encerrado.');

        expect(User::find($user->id))->toBeNull()
            ->and(AccessToken::where('user_id', $user->id)->count())->toBe(0)
            ->and(RefreshToken::where('user_id', $user->id)->count())->toBe(0);

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    });

    it('never deletes an admin', function () {
        $other = User::factory()->admin()->create();

        $this->deleteJson("/api/users/{$other->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Administrators cannot be deleted.');

        $this->deleteJson("/api/users/{$this->admin->id}")->assertUnprocessable();

        expect(User::count())->toBe(2);
    });

    it('returns 404 for an unknown user', function (string $id) {
        $this->deleteJson("/api/users/{$id}")
            ->assertNotFound()
            ->assertJsonPath('message.en-us', 'User not found.');
    })->with(['999', 'abc', '-1']);
});
