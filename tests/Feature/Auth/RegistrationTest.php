<?php

use App\Models\Auth\RegistrationRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

describe('POST /api/auth/register', function () {
    it('creates a registration request instead of a user', function () {
        $response = $this->postJson('/api/auth/register', ['name' => '  Maria Souza ', 'email' => ' MARIA@Example.com ', 'password' => 'secret123']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.pt-br', 'Pedido de cadastro enviado. Aguarde a aprovação de um administrador.');

        $request = RegistrationRequest::sole();

        expect($request->name)->toBe('Maria Souza')
            ->and($request->email)->toBe('maria@example.com')
            ->and(Hash::check('secret123', $request->password))->toBeTrue()
            ->and(User::count())->toBe(0);
    });

    it('is public (no session needed)', function () {
        $this->postJson('/api/auth/register', ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secret123'])
            ->assertCreated();
    });

    it('rejects invalid payload with 422', function (array $payload, string $message) {
        $this->postJson('/api/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.en-us', $message);
    })->with([
        [['email' => 'a@b.com', 'password' => 'secret123'], 'Name, email and password are required.'],
        [['name' => 'Ana', 'password' => 'secret123'], 'Name, email and password are required.'],
        [['name' => 'Ana', 'email' => 'a@b.com'], 'Name, email and password are required.'],
        [['name' => 'Ana', 'email' => 'not-an-email', 'password' => 'secret123'], 'Invalid email.'],
        [['name' => 'Ana', 'email' => 'a@b.com', 'password' => 'short'], 'Password must be at least 8 characters.'],
        [['name' => str_repeat('a', 151), 'email' => 'a@b.com', 'password' => 'secret123'], 'Name must be at most 150 characters.'],
    ]);

    it('returns 409 when the email belongs to a user', function () {
        User::factory()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/auth/register', ['name' => 'Ana', 'email' => 'ANA@example.com', 'password' => 'secret123'])
            ->assertConflict()
            ->assertJsonPath('message.en-us', 'A user or registration request with this email already exists.');
    });

    it('returns 409 when the email already has a pending request', function () {
        RegistrationRequest::factory()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/auth/register', ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secret123'])
            ->assertConflict();
    });

    it('returns 500 when the database fails', function () {
        failTable('registration_requests');

        $this->postJson('/api/auth/register', ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secret123'])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error sending the registration request.');
    });
});

describe('POST /api/auth/login with a pending request', function () {
    it('tells the requester the registration is awaiting approval', function () {
        RegistrationRequest::factory()->create(['email' => 'ana@example.com', 'password' => 'secret123']);

        $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'secret123'])
            ->assertForbidden()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.pt-br', 'Seu cadastro ainda aguarda a aprovação de um administrador.');
    });

    it('does not reveal the pending request to a wrong password', function () {
        RegistrationRequest::factory()->create(['email' => 'ana@example.com', 'password' => 'secret123']);

        $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'wrong-password'])
            ->assertUnauthorized()
            ->assertJsonPath('message.en-us', 'Invalid email or password.');
    });
});
