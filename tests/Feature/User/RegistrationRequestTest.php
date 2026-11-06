<?php

use App\Models\Auth\RegistrationRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->withToken(accessToken($this->admin));
});

describe('admin only', function () {
    it('returns 403 for a regular user', function (string $method, string $url) {
        $this->withToken(accessToken(User::factory()->create()))
            ->json($method, $url)
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.en-us', "You don't have permission to access this resource.");
    })->with([
        ['GET', '/api/registration-requests'],
        ['POST', '/api/registration-requests/1/approve'],
        ['DELETE', '/api/registration-requests/1'],
    ]);

    it('returns 401 without a session', function () {
        $this->withToken('')->flushHeaders();

        $this->getJson('/api/registration-requests')->assertUnauthorized();
    });
});

describe('GET /api/registration-requests', function () {
    it('lists the pending requests, oldest first, paginated', function () {
        RegistrationRequest::factory()->create(['name' => 'Bruno', 'created_at' => Carbon::parse('2026-11-02')]);
        RegistrationRequest::factory()->create(['name' => 'Ana', 'created_at' => Carbon::parse('2026-11-01')]);
        RegistrationRequest::factory()->create(['name' => 'Carla', 'created_at' => Carbon::parse('2026-11-03')]);

        $response = $this->getJson('/api/registration-requests?per_page=2');

        $response->assertOk()
            ->assertJsonPath('data.meta', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);

        expect(collect($response->json('data.items'))->pluck('name')->all())->toBe(['Ana', 'Bruno'])
            ->and(array_keys($response->json('data.items.0')))->toBe(['id', 'name', 'email', 'created_at']);
    });

    it('searches by name or email', function () {
        RegistrationRequest::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        RegistrationRequest::factory()->create(['name' => 'Bruno', 'email' => 'bruno@loja.com']);

        expect($this->getJson('/api/registration-requests?search=loja')->json('data.items.0.name'))->toBe('Bruno')
            ->and($this->getJson('/api/registration-requests?search=an')->json('data.meta.total'))->toBe(1);
    });

    it('returns 500 when the database fails', function () {
        failTable('registration_requests');

        $this->getJson('/api/registration-requests')
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error fetching registration requests.');
    });
});

describe('POST /api/registration-requests/{id}/approve', function () {
    it('creates a regular user with the requested password and deletes the request', function () {
        $request = RegistrationRequest::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secret123']);

        $this->postJson("/api/registration-requests/{$request->id}/approve")
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ana')
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('message.pt-br', 'Cadastro aprovado. O usuário já pode entrar.');

        expect(RegistrationRequest::count())->toBe(0);

        $this->withToken('')->flushHeaders();
        $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('data.user.is_admin', false);
    });

    it('returns 404 for an unknown request', function (string $id) {
        $this->postJson("/api/registration-requests/{$id}/approve")
            ->assertNotFound()
            ->assertJsonPath('message.en-us', 'Registration request not found.');
    })->with(['999', 'abc', '-1']);

    it('returns 409 when the email became a user meanwhile', function () {
        $request = RegistrationRequest::factory()->create(['email' => 'ana@example.com']);
        User::factory()->create(['email' => 'ana@example.com']);

        $this->postJson("/api/registration-requests/{$request->id}/approve")->assertConflict();

        expect(RegistrationRequest::count())->toBe(1);
    });

    it('returns 500 when the database fails', function () {
        failTable('registration_requests');

        $this->postJson('/api/registration-requests/1/approve')
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error approving the registration request.');
    });
});

describe('DELETE /api/registration-requests/{id}', function () {
    it('rejects by deleting the request', function () {
        $request = RegistrationRequest::factory()->create(['email' => 'ana@example.com']);

        $this->deleteJson("/api/registration-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.pt-br', 'Pedido de cadastro recusado e excluído.');

        expect(RegistrationRequest::count())->toBe(0)
            ->and(User::where('email', 'ana@example.com')->exists())->toBeFalse();
    });

    it('returns 404 for an unknown request', function () {
        $this->deleteJson('/api/registration-requests/5')->assertNotFound();
    });

    it('returns 500 when the database fails', function () {
        failTable('registration_requests');

        $this->deleteJson('/api/registration-requests/1')
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error rejecting the registration request.');
    });
});
