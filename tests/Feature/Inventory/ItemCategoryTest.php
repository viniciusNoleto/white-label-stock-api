<?php

use App\Models\Inventory\ItemCategory;
use App\Models\Inventory\Product;

beforeEach(function () {
    $this->withToken(accessToken());
});

describe('GET /api/item-categories', function () {
    it('lists categories ordered by name', function () {
        ItemCategory::factory()->create(['name' => 'Peças']);
        ItemCategory::factory()->create(['name' => 'Embalagens']);

        $response = $this->getJson('/api/item-categories')->assertOk();

        expect(collect($response->json('data.items'))->pluck('name')->all())->toBe(['Embalagens', 'Peças'])
            ->and(array_keys($response->json('data.items.0')))->toBe(['id', 'name', 'color_hex', 'created_at', 'updated_at'])
            ->and($response->json('data.meta'))->toBe(['page' => 1, 'per_page' => 8, 'total' => 2, 'last_page' => 1]);
    });

    it('paginates with page/per_page', function () {
        ItemCategory::factory()->create(['name' => 'Embalagens']);
        ItemCategory::factory()->create(['name' => 'Ferramentas']);
        ItemCategory::factory()->create(['name' => 'Peças']);

        $page = $this->getJson('/api/item-categories?page=2&per_page=2')->assertOk();

        expect($page->json('data.meta'))->toBe(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->and(collect($page->json('data.items'))->pluck('name')->all())->toBe(['Peças']);
    });

    it('returns 500 when the database fails', function () {
        failTable('item_categories');

        $this->getJson('/api/item-categories')
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error fetching categories.');
    });
});

describe('POST /api/item-categories', function () {
    it('creates a category', function () {
        $this->postJson('/api/item-categories', ['name' => 'Ferragens', 'color_hex' => '#ABCDEF'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ferragens')
            ->assertJsonPath('data.color_hex', '#ABCDEF')
            ->assertJsonPath('message.pt-br', 'Categoria criada com sucesso.');
    });

    it('requires name and color', function (array $payload) {
        $this->postJson('/api/item-categories', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Nome e cor são obrigatórios.');
    })->with([
        [['name' => 'x']],
        [['color_hex' => '#000000']],
    ]);

    it('rejects colors outside #RRGGBB', function () {
        $this->postJson('/api/item-categories', ['name' => 'x', 'color_hex' => 'red'])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Invalid color. Use the #RRGGBB format.');
    });
});

describe('PUT /api/item-categories/{item_category_id}', function () {
    it('updates a category', function () {
        $category = ItemCategory::factory()->create();

        $this->putJson("/api/item-categories/{$category->id}", ['name' => 'Novo', 'color_hex' => '#000000'])
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.name', 'Novo')
            ->assertJsonPath('data.color_hex', '#000000');
    });

    it('returns 404 for an unknown category', function () {
        $this->putJson('/api/item-categories/99', ['name' => 'a', 'color_hex' => '#000000'])
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Categoria não encontrada.');
    });

    it('returns 500 when the database fails', function () {
        failTable('item_categories');

        $this->putJson('/api/item-categories/1', ['name' => 'a', 'color_hex' => '#000000'])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error updating category.');
    });
});

describe('DELETE /api/item-categories/{item_category_id}', function () {
    it('deletes a category', function () {
        $category = ItemCategory::factory()->create();

        $this->deleteJson("/api/item-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Categoria excluída com sucesso.');
    });

    it('returns 404 for an unknown category', function () {
        $this->deleteJson('/api/item-categories/99')->assertNotFound();
    });

    it('returns 409 while the category is linked to an item', function () {
        $category = ItemCategory::factory()->create();
        Product::factory()->create()->categories()->attach($category->id);

        $this->deleteJson("/api/item-categories/{$category->id}")
            ->assertConflict()
            ->assertJsonPath('message.en-us', 'This category is in use by products.');
    });
});
