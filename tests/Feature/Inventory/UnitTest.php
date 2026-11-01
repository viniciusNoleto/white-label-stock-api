<?php

use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\Unit;

beforeEach(function () {
    $this->withUnencryptedCookies(sessionCookies());
});

describe('GET /api/units', function () {
    it('lists units ordered by name in the API envelope', function () {
        Unit::factory()->create(['name' => 'Metro', 'abbreviation' => 'm']);
        Unit::factory()->create(['name' => 'Grama', 'abbreviation' => 'g']);

        $response = $this->getJson('/api/units');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message.pt-br', 'Operação realizada com sucesso.');

        expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Grama', 'Metro'])
            ->and(array_keys($response->json('data.0')))->toBe(['id', 'name', 'abbreviation', 'created_at', 'updated_at']);
    });

    it('returns 500 when the database fails', function () {
        failTable('units');

        $this->getJson('/api/units')
            ->assertInternalServerError()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.en-us', 'Error fetching units.');
    });
});

describe('POST /api/units', function () {
    it('creates a unit trimming the fields', function () {
        $response = $this->postJson('/api/units', ['name' => '  Litro ', 'abbreviation' => 'L']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Litro')
            ->assertJsonPath('data.abbreviation', 'L')
            ->assertJsonPath('message.pt-br', 'Unidade criada com sucesso.');

        expect($response->json('data.id'))->toBeInt()
            ->and($response->json('data.created_at'))->not->toBeNull();
    });

    it('rejects invalid payload with 422', function (array $payload) {
        $this->postJson('/api/units', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Nome e abreviação são obrigatórios.');
    })->with([
        [['abbreviation' => 'L']],
        [['name' => 'Litro']],
        [['name' => '  ', 'abbreviation' => '']],
        [['name' => 1, 'abbreviation' => 2]],
    ]);

    it('rejects malformed JSON with 400', function () {
        $this->call('POST', '/api/units', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{oops')
            ->assertBadRequest()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message.en-us', 'Invalid data.');
    });

    it('returns 500 when the database fails', function () {
        failTable('units');

        $this->postJson('/api/units', ['name' => 'Litro', 'abbreviation' => 'L'])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error creating unit.');
    });
});

describe('PUT /api/units/{unit_id}', function () {
    it('updates an existing unit', function () {
        $unit = Unit::factory()->create();

        $this->putJson("/api/units/{$unit->id}", ['name' => 'Tonelada', 'abbreviation' => 't'])
            ->assertOk()
            ->assertJsonPath('data.id', $unit->id)
            ->assertJsonPath('data.name', 'Tonelada')
            ->assertJsonPath('data.abbreviation', 't')
            ->assertJsonPath('message.pt-br', 'Unidade atualizada com sucesso.');
    });

    it('validates required fields', function () {
        $unit = Unit::factory()->create();

        $this->putJson("/api/units/{$unit->id}", ['name' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    });

    it('returns 404 for unknown id', function (string $id) {
        $this->putJson("/api/units/{$id}", ['name' => 'a', 'abbreviation' => 'b'])
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Unidade não encontrada.');
    })->with(['999', 'abc', '-1']);

    it('returns 500 when the database fails', function () {
        failTable('units');

        $this->putJson('/api/units/1', ['name' => 'a', 'abbreviation' => 'b'])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error updating unit.');
    });
});

describe('DELETE /api/units/{unit_id}', function () {
    it('deletes an existing unit', function () {
        $unit = Unit::factory()->create();

        $this->deleteJson("/api/units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.pt-br', 'Unidade excluída com sucesso.');

        expect($this->getJson('/api/units')->json('data'))->toBeEmpty();
    });

    it('returns 404 for an unknown unit', function () {
        $this->deleteJson('/api/units/5')->assertNotFound();
    });

    it('returns 409 while the unit is used by an item', function () {
        $unit = Unit::factory()->create();
        InventoryItem::factory()->create(['unit_id' => $unit->id]);

        $this->deleteJson("/api/units/{$unit->id}")
            ->assertConflict()
            ->assertJsonPath('message.en-us', 'This unit is in use by inventory items.');
    });

    it('returns 500 when the database fails', function () {
        failTable('units');

        $this->deleteJson('/api/units/1')
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error deleting unit.');
    });
});
