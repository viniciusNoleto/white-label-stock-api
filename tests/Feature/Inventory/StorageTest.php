<?php

use App\Models\Inventory\Item;
use App\Models\Inventory\Storage;

beforeEach(function () {
    $this->withToken(accessToken());
});

describe('GET /api/storages', function () {
    it('lists storages ordered by name', function () {
        Storage::factory()->create(['name' => 'Galpão']);
        Storage::factory()->create(['name' => 'Almoxarifado', 'description' => 'Térreo']);

        $response = $this->getJson('/api/storages')->assertOk();

        expect(collect($response->json('data.items'))->pluck('name')->all())->toBe(['Almoxarifado', 'Galpão'])
            ->and(array_keys($response->json('data.items.0')))->toBe(['id', 'name', 'description', 'created_at', 'updated_at'])
            ->and($response->json('data.meta'))->toBe(['page' => 1, 'per_page' => 8, 'total' => 2, 'last_page' => 1]);
    });

    it('paginates with page/per_page', function () {
        Storage::factory()->create(['name' => 'Almoxarifado']);
        Storage::factory()->create(['name' => 'Depósito']);
        Storage::factory()->create(['name' => 'Galpão']);

        $page = $this->getJson('/api/storages?page=2&per_page=2')->assertOk();

        expect($page->json('data.meta'))->toBe(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->and(collect($page->json('data.items'))->pluck('name')->all())->toBe(['Galpão']);
    });

    it('returns 500 when the database fails', function () {
        failTable('storages');

        $this->getJson('/api/storages')
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error fetching storages.');
    });
});

describe('POST /api/storages', function () {
    it('creates a storage, storing a blank description as null', function () {
        $this->postJson('/api/storages', ['name' => 'Depósito', 'description' => '   '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Depósito')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('message.pt-br', 'Armazenamento criado com sucesso.');
    });

    it('requires a name', function () {
        $this->postJson('/api/storages', ['description' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Nome é obrigatório.');
    });
});

describe('PUT /api/storages/{storage_id}', function () {
    it('updates a storage', function () {
        $storage = Storage::factory()->create();

        $this->putJson("/api/storages/{$storage->id}", ['name' => 'Novo', 'description' => 'Fundos'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Novo')
            ->assertJsonPath('data.description', 'Fundos');
    });

    it('returns 404 for an unknown storage', function () {
        $this->putJson('/api/storages/99', ['name' => 'a'])
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Armazenamento não encontrado.');
    });
});

describe('DELETE /api/storages/{storage_id}', function () {
    it('deletes a storage', function () {
        $storage = Storage::factory()->create();

        $this->deleteJson("/api/storages/{$storage->id}")->assertOk();
    });

    it('returns 409 while the storage holds items', function () {
        $storage = Storage::factory()->create();
        Item::factory()->create(['storage_id' => $storage->id]);

        $this->deleteJson("/api/storages/{$storage->id}")
            ->assertConflict()
            ->assertJsonPath('message.en-us', 'This storage is in use by stock items.');
    });

    it('returns 500 when the database fails', function () {
        failTable('storages');

        $this->deleteJson('/api/storages/1')
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error deleting storage.');
    });
});
