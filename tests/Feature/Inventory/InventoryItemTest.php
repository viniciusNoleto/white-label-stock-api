<?php

use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\ItemCategory;
use App\Models\Inventory\Storage;
use App\Models\Inventory\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->withToken(accessToken());
});

function listItems(string $query = ''): TestResponse {
    return test()->getJson("/api/inventory-items{$query}");
}

function itemQuantities(): array {
    return collect(listItems()->json('data.items'))->pluck('quantity', 'name')->all();
}

describe('GET /api/inventory-items', function () {
    it('returns an empty first page', function () {
        listItems()
            ->assertOk()
            ->assertJsonPath('data', ['items' => [], 'meta' => ['page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]]);
    });

    it('returns items with nested unit, storage, categories and components', function () {
        $unit = Unit::factory()->create(['name' => 'Unidade', 'abbreviation' => 'un']);
        $storage = Storage::factory()->create(['name' => 'Depósito']);
        $category = ItemCategory::factory()->create(['name' => 'Móveis', 'color_hex' => '#ff0000']);
        $wood = InventoryItem::factory()->create(['name' => 'Madeira', 'quantity' => '10', 'unit_id' => $unit->id]);
        $chair = InventoryItem::factory()->create(['name' => 'Cadeira', 'quantity' => '2', 'unit_id' => $unit->id, 'storage_id' => $storage->id]);
        $chair->categories()->attach($category->id);
        $chair->components()->attach($wood->id, ['quantity_required' => '2.5']);

        $response = listItems();
        [$first, $second] = $response->json('data.items');

        expect($response->json('data.meta.total'))->toBe(2)
            ->and(array_keys($first))->toBe(['id', 'name', 'quantity', 'packaged_quantity', 'created_at', 'updated_at', 'unit', 'storage', 'categories', 'components'])
            ->and($first)->toMatchArray([
                'id' => $chair->id,
                'name' => 'Cadeira',
                'quantity' => '2.0000',
                'packaged_quantity' => '0.0000',
                'unit' => ['id' => $unit->id, 'name' => 'Unidade', 'abbreviation' => 'un'],
                'storage' => ['id' => $storage->id, 'name' => 'Depósito'],
                'categories' => [['id' => $category->id, 'name' => 'Móveis', 'color_hex' => '#ff0000']],
                'components' => [['id' => $wood->id, 'name' => 'Madeira', 'quantity_required' => '2.5000', 'current_quantity' => '10.0000']],
            ])
            ->and($second)->toMatchArray(['name' => 'Madeira', 'storage' => null, 'categories' => [], 'components' => []]);
    });

    it('filters by name case-insensitively', function () {
        InventoryItem::factory()->create(['name' => 'Parafuso Sextavado']);
        InventoryItem::factory()->create(['name' => 'Porca']);

        $response = listItems('?search=parafuso');

        expect(collect($response->json('data.items'))->pluck('name')->all())->toBe(['Parafuso Sextavado'])
            ->and($response->json('data.meta.total'))->toBe(1);
    });

    it('paginates and clamps page/per_page', function () {
        $unit = Unit::factory()->create();
        foreach (['A', 'B', 'C'] as $name) {
            InventoryItem::factory()->create(['name' => $name, 'unit_id' => $unit->id]);
        }

        $page2 = listItems('?page=2&per_page=2')->json('data');
        expect(collect($page2['items'])->pluck('name')->all())->toBe(['C'])
            ->and($page2['meta'])->toBe(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);

        expect(listItems('?page=0&per_page=500')->json('data.meta'))->toMatchArray(['page' => 1, 'per_page' => 100])
            ->and(listItems('?page=abc&per_page=-5')->json('data.meta'))->toMatchArray(['page' => 1, 'per_page' => 1]);
    });

    it('returns 500 when the database fails', function () {
        failTable('inventory_items');

        listItems()
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error fetching items.');
    });
});

describe('POST /api/inventory-items', function () {
    it('creates a simple item with default quantity', function () {
        $unit = Unit::factory()->create();

        $this->postJson('/api/inventory-items', ['name' => 'Prego', 'unit_id' => (string)$unit->id])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Prego')
            ->assertJsonPath('data.quantity', '0.0000')
            ->assertJsonPath('data.unit_id', $unit->id)
            ->assertJsonPath('data.storage_id', null)
            ->assertJsonPath('message.pt-br', 'Item criado com sucesso.');
    });

    it('creates an item with storage, categories and components', function () {
        $unit = Unit::factory()->create();
        $storage = Storage::factory()->create();
        $category = ItemCategory::factory()->create();
        $component = InventoryItem::factory()->create(['name' => 'Tábua', 'unit_id' => $unit->id]);

        $response = $this->postJson('/api/inventory-items', [
            'name' => 'Mesa',
            'unit_id' => $unit->id,
            'storage_id' => $storage->id,
            'quantity' => 3,
            'category_ids' => [$category->id, $category->id],
            'components' => [['id' => $component->id, 'quantity_required' => 4]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Mesa')
            ->assertJsonPath('data.quantity', '3.0000')
            ->assertJsonPath('data.storage_id', $storage->id);

        $id = $response->json('data.id');

        expect(DB::table('inventory_item_has_category')->get(['inventory_item_id', 'item_category_id'])->map(fn($row) => (array)$row)->all())
            ->toBe([['inventory_item_id' => $id, 'item_category_id' => $category->id]])
            ->and(DB::table('inventory_item_has_component')->get(['inventory_item_id', 'component_inventory_item_id', 'quantity_required'])->map(fn($row) => (array)$row)->all())
            ->toBe([['inventory_item_id' => $id, 'component_inventory_item_id' => $component->id, 'quantity_required' => '4.0000']]);
    });

    it('requires name and unit', function (array $payload) {
        $this->postJson('/api/inventory-items', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Nome e unidade são obrigatórios.');
    })->with([
        [['name' => 'x']],
        [['unit_id' => 1]],
        [['name' => '  ', 'unit_id' => 1]],
    ]);

    it('rejects an invalid quantity', function (mixed $quantity) {
        $unit = Unit::factory()->create();

        $this->postJson('/api/inventory-items', ['name' => 'x', 'unit_id' => $unit->id, 'quantity' => $quantity])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Invalid quantity.');
    })->with([-1, 'abc']);

    it('rejects components without a positive quantity', function () {
        $component = InventoryItem::factory()->create();

        $this->postJson('/api/inventory-items', ['name' => 'x', 'unit_id' => $component->unit_id, 'components' => [['id' => $component->id, 'quantity_required' => 0]]])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Each component needs an item and a quantity greater than zero.');
    });

    it('returns 422 when a reference does not exist and creates nothing (transaction)', function () {
        $unit = Unit::factory()->create();

        $this->postJson('/api/inventory-items', ['name' => 'x', 'unit_id' => $unit->id, 'category_ids' => [999]])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'The given unit, storage, category or component does not exist.');

        expect(listItems()->json('data.items'))->toBeEmpty();
    });

    it('returns 500 when the database fails', function () {
        failTable('inventory_items');
        $unit = Unit::factory()->create();

        $this->postJson('/api/inventory-items', ['name' => 'x', 'unit_id' => $unit->id])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error creating item.');
    });
});

describe('PATCH /api/inventory-items/{inventory_item_id}', function () {
    function seedItem(string $quantity = '10'): InventoryItem {
        return InventoryItem::factory()->create(['name' => 'Cabo', 'quantity' => $quantity]);
    }

    it('sets an absolute quantity', function () {
        $item = seedItem();

        $this->patchJson("/api/inventory-items/{$item->id}", ['quantity' => 7.5])
            ->assertOk()
            ->assertJsonPath('data.quantity', '7.5000')
            ->assertJsonPath('message.pt-br', 'Quantidade atualizada com sucesso.');
    });

    it('adds and subtracts atomically', function () {
        $item = seedItem('10');

        expect($this->patchJson("/api/inventory-items/{$item->id}", ['quantity_add' => 5])->json('data.quantity'))->toBe('15.0000')
            ->and($this->patchJson("/api/inventory-items/{$item->id}", ['quantity_subtract' => 3])->json('data.quantity'))->toBe('12.0000');
    });

    it('rejects an invalid quantity', function (array $payload) {
        $item = seedItem();

        $this->patchJson("/api/inventory-items/{$item->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Invalid quantity.');
    })->with([
        [['quantity' => -1]],
        [['quantity' => 'x']],
        [['quantity_add' => 'x']],
        [['quantity_add' => 0]],
        [['quantity_add' => -2]],
        [['quantity_subtract' => 0]],
        [['quantity_subtract' => -2]],
    ]);

    it('subtracts the whole stock down to zero', function () {
        $item = seedItem('2.5');

        $this->patchJson("/api/inventory-items/{$item->id}", ['quantity_subtract' => 2.5])
            ->assertOk()
            ->assertJsonPath('data.quantity', '0.0000');
    });

    it('rejects subtracting more than the stock and keeps the quantity', function () {
        $item = seedItem('3');

        $this->patchJson("/api/inventory-items/{$item->id}", ['quantity_subtract' => 4.5])
            ->assertUnprocessable()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.pt-br', 'Estoque insuficiente de "Cabo". Necessário: 4.5, Disponível: 3.');

        expect($item->fresh()->quantity)->toBe('3.0000');
    });

    it('rejects more than one quantity mode at once', function (array $payload) {
        $item = seedItem();

        $this->patchJson("/api/inventory-items/{$item->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Send only one quantity mode: set, add or subtract.');

        expect($item->fresh()->quantity)->toBe('10.0000');
    })->with([
        [['quantity' => 1, 'quantity_add' => 2]],
        [['quantity_add' => 1, 'quantity_subtract' => 2]],
        [['quantity' => 1, 'quantity_subtract' => 2]],
    ]);

    it('returns 404 when subtracting from an unknown item', function () {
        $this->patchJson('/api/inventory-items/404', ['quantity_subtract' => 1])
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Item não encontrado.');
    });

    it('renames an item trimming whitespace', function () {
        $item = seedItem();

        $this->patchJson("/api/inventory-items/{$item->id}", ['name' => '  Cabo USB  '])
            ->assertJsonPath('data.name', 'Cabo USB')
            ->assertJsonPath('message.pt-br', 'Nome atualizado com sucesso.');
    });

    it('rejects a blank name', function () {
        $item = seedItem();

        $this->patchJson("/api/inventory-items/{$item->id}", ['name' => '   '])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Nome é obrigatório.');
    });

    it('sets and clears the storage', function () {
        $item = seedItem();
        $storage = Storage::factory()->create();

        $this->patchJson("/api/inventory-items/{$item->id}", ['storage_id' => $storage->id])
            ->assertJsonPath('data.storage_id', $storage->id)
            ->assertJsonPath('message.pt-br', 'Armazenamento atualizado com sucesso.');

        $this->patchJson("/api/inventory-items/{$item->id}", ['storage_id' => null])
            ->assertJsonPath('data.storage_id', null);
    });

    it('edits name, unit and categories together', function () {
        $item = seedItem();
        $unit = Unit::factory()->create();
        [$keep, $drop, $add] = ItemCategory::factory()->count(3)->create();
        $item->categories()->attach([$keep->id, $drop->id], ['created_at' => '2026-01-01 00:00:00']);

        $this->patchJson("/api/inventory-items/{$item->id}", [
            'name' => 'Cabo novo',
            'unit_id' => $unit->id,
            'category_ids' => [$keep->id, $add->id, $add->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cabo novo')
            ->assertJsonPath('data.unit_id', $unit->id)
            ->assertJsonPath('data.quantity', '10.0000')
            ->assertJsonPath('message.pt-br', 'Item atualizado com sucesso.');

        $pivot = DB::table('inventory_item_has_category')->where('inventory_item_id', $item->id)->orderBy('item_category_id')->get();

        expect($pivot->pluck('item_category_id')->all())->toBe([$keep->id, $add->id])
            ->and($pivot->firstWhere('item_category_id', $keep->id)->created_at)->toBe('2026-01-01 00:00:00')
            ->and($pivot->firstWhere('item_category_id', $add->id)->created_at)->not->toBeNull();
    });

    it('clears all categories with an empty list', function () {
        $item = seedItem();
        $item->categories()->attach(ItemCategory::factory()->create()->id);

        $this->patchJson("/api/inventory-items/{$item->id}", ['category_ids' => []])
            ->assertOk()
            ->assertJsonPath('message.en-us', 'Item updated successfully.');

        expect($item->categories()->count())->toBe(0);
    });

    it('rejects an invalid unit or category list', function (array $payload, string $message) {
        $item = seedItem();

        $this->patchJson("/api/inventory-items/{$item->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', $message);
    })->with([
        [['unit_id' => null], 'Unit is required.'],
        [['unit_id' => 'x'], 'Unit is required.'],
        [['category_ids' => 'x'], 'Invalid categories.'],
    ]);

    it('rolls back everything when a category does not exist', function () {
        $item = seedItem();
        $unit = Unit::factory()->create();

        $this->patchJson("/api/inventory-items/{$item->id}", ['name' => 'Outro', 'unit_id' => $unit->id, 'category_ids' => [999]])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'The given unit, storage, category or component does not exist.');

        expect($item->fresh()->only('name', 'unit_id'))->toBe(['name' => 'Cabo', 'unit_id' => $item->unit_id]);
    });

    it('returns 422 for a unit that does not exist', function () {
        $item = seedItem();

        $this->patchJson("/api/inventory-items/{$item->id}", ['unit_id' => 999])->assertUnprocessable();
    });

    it('returns 422 for a storage that does not exist', function () {
        $item = seedItem();

        $this->patchJson("/api/inventory-items/{$item->id}", ['storage_id' => 999])->assertUnprocessable();
    });

    it('rejects a payload without updatable fields', function () {
        $this->patchJson('/api/inventory-items/1', ['foo' => 'bar', 'quantity' => null])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'No field to update was provided.');
    });

    it('returns 404 for an unknown item', function () {
        $this->patchJson('/api/inventory-items/404', ['quantity' => 1])
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Item não encontrado.');
    });

    it('returns 500 when the database fails', function () {
        failTable('inventory_items');

        $this->patchJson('/api/inventory-items/1', ['quantity' => 1])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error updating the item.');
    });
});

describe('POST /api/inventory-items/{inventory_item_id}/build', function () {
    function seedRecipe(string $wood = '10', string $nails = '20'): InventoryItem {
        $unit = Unit::factory()->create();
        $wood_item = InventoryItem::factory()->create(['name' => 'Madeira', 'quantity' => $wood, 'unit_id' => $unit->id]);
        $nail_item = InventoryItem::factory()->create(['name' => 'Prego', 'quantity' => $nails, 'unit_id' => $unit->id]);
        $chair = InventoryItem::factory()->create(['name' => 'Cadeira', 'quantity' => '1', 'unit_id' => $unit->id]);
        $chair->components()->attach([
            $wood_item->id => ['quantity_required' => '2'],
            $nail_item->id => ['quantity_required' => '8'],
        ]);

        return $chair;
    }

    it('consumes components and increments the built item', function () {
        $chair = seedRecipe();

        $this->postJson("/api/inventory-items/{$chair->id}/build", ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.quantity', '3.0000')
            ->assertJsonPath('message.pt-br', '2 unidade(s) construída(s) com sucesso.');

        expect(itemQuantities())->toBe(['Cadeira' => '3.0000', 'Madeira' => '6.0000', 'Prego' => '4.0000']);
    });

    it('defaults to building one unit', function () {
        $chair = seedRecipe();

        expect($this->postJson("/api/inventory-items/{$chair->id}/build", [])->json('data.quantity'))->toBe('2.0000');
    });

    it('builds fractional quantities', function () {
        $chair = seedRecipe();

        $this->postJson("/api/inventory-items/{$chair->id}/build", ['quantity' => 0.5])
            ->assertOk()
            ->assertJsonPath('message.en-us', '0.5 unit(s) built successfully.');

        expect(itemQuantities())->toBe(['Cadeira' => '1.5000', 'Madeira' => '9.0000', 'Prego' => '16.0000']);
    });

    it('rejects a non positive quantity', function (mixed $quantity) {
        $this->postJson('/api/inventory-items/1/build', ['quantity' => $quantity])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Quantidade deve ser maior que zero.');
    })->with([0, -1, 'x']);

    it('rejects items without components', function () {
        $item = InventoryItem::factory()->create();

        $this->postJson("/api/inventory-items/{$item->id}/build", ['quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Este item não possui componentes cadastrados.');
    });

    it('rejects when stock is insufficient and leaves quantities untouched (rollback)', function () {
        $chair = seedRecipe(nails: '10');

        $this->postJson("/api/inventory-items/{$chair->id}/build", ['quantity' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Estoque insuficiente de "Prego". Necessário: 16, Disponível: 10.');

        expect(itemQuantities())->toBe(['Cadeira' => '1.0000', 'Madeira' => '10.0000', 'Prego' => '10.0000']);
    });

    it('returns 500 when the database fails', function () {
        failTable('inventory_item_has_component');

        $this->postJson('/api/inventory-items/1/build', ['quantity' => 1])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error building item.');
    });
});
