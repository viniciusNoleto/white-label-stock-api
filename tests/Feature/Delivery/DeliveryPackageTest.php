<?php

use App\Models\Delivery\DeliveryPackage;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->withToken(accessToken());
});

/**
 * @return array{0: InventoryItem, 1: InventoryItem}
 */
function seedDindins(string $coco = '10', string $morango = '5'): array {
    $unit = Unit::factory()->create(['name' => 'Unidade', 'abbreviation' => 'un']);

    return [
        InventoryItem::factory()->create(['name' => 'Dindin de coco', 'quantity' => $coco, 'unit_id' => $unit->id]),
        InventoryItem::factory()->create(['name' => 'Dindin de morango', 'quantity' => $morango, 'unit_id' => $unit->id]),
    ];
}

function createPackage(mixed $items, ?string $notes = null): TestResponse {
    return test()->postJson('/api/delivery-packages', ['items' => $items, 'notes' => $notes]);
}

function stockByName(): array {
    return collect(test()->getJson('/api/inventory-items')->json('data.items'))
        ->mapWithKeys(fn($item) => [$item['name'] => [$item['quantity'], $item['packaged_quantity']]])
        ->all();
}

describe('POST /api/delivery-packages', function () {
    it('creates a pending package with items and notes', function () {
        [$coco, $morango] = seedDindins();

        $response = createPackage([['id' => $morango->id, 'quantity' => 2], ['id' => (string)$coco->id, 'quantity' => '3']], "Rua A, 10\nEntregar após 14h");

        $response->assertCreated()
            ->assertJsonPath('message.pt-br', 'Pacote criado com sucesso.')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.notes', "Rua A, 10\nEntregar após 14h")
            ->assertJsonPath('data.delivered_at', null);

        expect(array_keys($response->json('data')))->toBe(['id', 'status', 'notes', 'delivered_at', 'created_at', 'updated_at', 'items'])
            ->and($response->json('data.items'))->toBe([
                ['id' => $coco->id, 'name' => 'Dindin de coco', 'quantity' => '3.0000', 'current_quantity' => '10.0000', 'unit' => ['id' => $coco->unit_id, 'name' => 'Unidade', 'abbreviation' => 'un']],
                ['id' => $morango->id, 'name' => 'Dindin de morango', 'quantity' => '2.0000', 'current_quantity' => '5.0000', 'unit' => ['id' => $morango->unit_id, 'name' => 'Unidade', 'abbreviation' => 'un']],
            ]);
    });

    it('does not subtract the stock but shows it as packaged in the items list', function () {
        [$coco, $morango] = seedDindins();

        createPackage([['id' => $coco->id, 'quantity' => 3]])->assertCreated();
        createPackage([['id' => $coco->id, 'quantity' => 2], ['id' => $morango->id, 'quantity' => 1.5]])->assertCreated();
        createPackage([['id' => $morango->id, 'quantity' => 1]])->assertCreated();
        DeliveryPackage::factory()->delivered()->create()->items()->attach($coco->id, ['quantity' => '4']);

        expect(stockByName())->toBe([
            'Dindin de coco' => ['10.0000', '5.0000'],
            'Dindin de morango' => ['5.0000', '2.5000'],
        ]);
    });

    it('stores blank notes as null', function () {
        [$coco] = seedDindins();

        createPackage([['id' => $coco->id, 'quantity' => 1]], "   \n  ")->assertJsonPath('data.notes', null);
    });

    it('rejects packaging more than the free stock and creates nothing', function () {
        [$coco] = seedDindins('10');
        createPackage([['id' => $coco->id, 'quantity' => 7]])->assertCreated();

        createPackage([['id' => $coco->id, 'quantity' => 4]])
            ->assertUnprocessable()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.pt-br', 'Estoque livre insuficiente de "Dindin de coco" para empacotar. Necessário: 4, Disponível: 3.');

        expect(DeliveryPackage::count())->toBe(1);
    });

    it('rejects invalid items', function (mixed $items) {
        seedDindins();

        createPackage($items)
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Select at least one item, each with a quantity greater than zero.');
    })->with([
        'missing' => [null],
        'empty' => [[]],
        'not a list' => ['x'],
        'no id' => [[['quantity' => 1]]],
        'invalid id' => [[['id' => 'abc', 'quantity' => 1]]],
    ]);

    it('rejects an invalid quantity', function (mixed $quantity) {
        [$coco] = seedDindins();

        createPackage([['id' => $coco->id, 'quantity' => $quantity]])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Select at least one item, each with a quantity greater than zero.');
    })->with([null, 0, -1, 'abc']);

    it('rejects the same item twice', function () {
        [$coco] = seedDindins();

        createPackage([['id' => $coco->id, 'quantity' => 1], ['id' => $coco->id, 'quantity' => 2]])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Each item can appear only once in the package.');
    });

    it('rejects notes that are too long', function () {
        [$coco] = seedDindins();

        createPackage([['id' => $coco->id, 'quantity' => 1]], str_repeat('a', 10001))
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Notes must be a text of up to 10000 characters.');
    });

    it('returns 422 for an item that does not exist and creates nothing', function () {
        [$coco] = seedDindins();

        createPackage([['id' => $coco->id, 'quantity' => 1], ['id' => 999, 'quantity' => 1]])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Some of the given items do not exist.');

        expect(DeliveryPackage::count())->toBe(0);
    });

    it('returns 500 when the database fails', function () {
        [$coco] = seedDindins();
        failTable('delivery_packages');

        createPackage([['id' => $coco->id, 'quantity' => 1]])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error creating package.');
    });
});

describe('GET /api/delivery-packages', function () {
    it('lists the most recent first, filters by status and paginates', function () {
        [$coco] = seedDindins();
        $old = DeliveryPackage::factory()->delivered()->create(['created_at' => now()->subDay()]);
        $new = DeliveryPackage::factory()->create(['notes' => 'Maria']);
        $new->items()->attach($coco->id, ['quantity' => '2']);

        $all = $this->getJson('/api/delivery-packages')->assertOk()->json('data');

        expect(collect($all['items'])->pluck('id')->all())->toBe([$new->id, $old->id])
            ->and($all['items'][0]['items'][0])->toMatchArray(['id' => $coco->id, 'quantity' => '2.0000'])
            ->and($all['meta'])->toBe(['page' => 1, 'per_page' => 15, 'total' => 2, 'last_page' => 1])
            ->and(collect($this->getJson('/api/delivery-packages?status=delivered')->json('data.items'))->pluck('id')->all())->toBe([$old->id])
            ->and(collect($this->getJson('/api/delivery-packages?status=PENDING')->json('data.items'))->pluck('id')->all())->toBe([$new->id])
            ->and($this->getJson('/api/delivery-packages?status=xyz')->json('data.meta.total'))->toBe(2)
            ->and($this->getJson('/api/delivery-packages?page=2&per_page=1')->json('data.items.0.id'))->toBe($old->id);
    });

    it('returns 500 when the database fails', function () {
        failTable('delivery_packages');

        $this->getJson('/api/delivery-packages')
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error fetching packages.');
    });
});

describe('GET /api/delivery-packages/{delivery_package_id}', function () {
    it('returns one package', function () {
        $package = DeliveryPackage::factory()->create(['notes' => 'João']);

        $this->getJson("/api/delivery-packages/{$package->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $package->id)
            ->assertJsonPath('data.notes', 'João')
            ->assertJsonPath('data.items', []);
    });

    it('returns 404 for an unknown package', function (string $id) {
        $this->getJson("/api/delivery-packages/{$id}")
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Pacote não encontrado.');
    })->with(['404', 'abc']);
});

describe('PUT /api/delivery-packages/{delivery_package_id}', function () {
    it('replaces notes and items, counting its own items as free', function () {
        [$coco, $morango] = seedDindins('10', '5');
        $id = createPackage([['id' => $coco->id, 'quantity' => 8]], 'Antes')->json('data.id');

        $this->putJson("/api/delivery-packages/{$id}", ['items' => [['id' => $coco->id, 'quantity' => 10], ['id' => $morango->id, 'quantity' => 1]], 'notes' => 'Depois'])
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Pacote atualizado com sucesso.')
            ->assertJsonPath('data.notes', 'Depois')
            ->assertJsonPath('data.items.0.quantity', '10.0000')
            ->assertJsonPath('data.items.1.id', $morango->id);

        $this->putJson("/api/delivery-packages/{$id}", ['items' => [['id' => $morango->id, 'quantity' => 2]]])
            ->assertOk()
            ->assertJsonPath('data.notes', null);

        expect(stockByName())->toBe([
            'Dindin de coco' => ['10.0000', '0.0000'],
            'Dindin de morango' => ['5.0000', '2.0000'],
        ]);
    });

    it('rejects more than the free stock and keeps the package', function () {
        [$coco] = seedDindins('10');
        createPackage([['id' => $coco->id, 'quantity' => 6]]);
        $id = createPackage([['id' => $coco->id, 'quantity' => 1]], 'Original')->json('data.id');

        $this->putJson("/api/delivery-packages/{$id}", ['items' => [['id' => $coco->id, 'quantity' => 5]], 'notes' => 'Novo'])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Not enough free stock of "Dindin de coco" to package. Required: 5, Available: 4.');

        $this->getJson("/api/delivery-packages/{$id}")
            ->assertJsonPath('data.notes', 'Original')
            ->assertJsonPath('data.items.0.quantity', '1.0000');
    });

    it('does not edit a delivered package', function () {
        [$coco] = seedDindins();
        $package = DeliveryPackage::factory()->delivered()->create();

        $this->putJson("/api/delivery-packages/{$package->id}", ['items' => [['id' => $coco->id, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Este pacote já foi entregue.');
    });

    it('returns 404 for an unknown package', function () {
        [$coco] = seedDindins();

        $this->putJson('/api/delivery-packages/404', ['items' => [['id' => $coco->id, 'quantity' => 1]]])
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Pacote não encontrado.');
    });

    it('returns 500 when the database fails', function () {
        [$coco] = seedDindins();
        $package = DeliveryPackage::factory()->create();
        failTable('delivery_package_has_item');

        $this->putJson("/api/delivery-packages/{$package->id}", ['items' => [['id' => $coco->id, 'quantity' => 1]]])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error updating package.');
    });
});

describe('POST /api/delivery-packages/{delivery_package_id}/deliver', function () {
    it('subtracts the items from the stock and marks the package as delivered', function () {
        [$coco, $morango] = seedDindins('10', '5');
        $id = createPackage([['id' => $coco->id, 'quantity' => 3], ['id' => $morango->id, 'quantity' => 2.5]])->json('data.id');
        createPackage([['id' => $coco->id, 'quantity' => 1]]);

        $this->postJson("/api/delivery-packages/{$id}/deliver")
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Pacote entregue. Os itens foram subtraídos do estoque.')
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.items.0.current_quantity', '7.0000');

        expect(DeliveryPackage::find($id)->delivered_at)->not->toBeNull()
            ->and(stockByName())->toBe([
                'Dindin de coco' => ['7.0000', '1.0000'],
                'Dindin de morango' => ['2.5000', '0.0000'],
            ]);
    });

    it('does not deliver twice', function () {
        [$coco] = seedDindins('10');
        $id = createPackage([['id' => $coco->id, 'quantity' => 3]])->json('data.id');
        $this->postJson("/api/delivery-packages/{$id}/deliver")->assertOk();

        $this->postJson("/api/delivery-packages/{$id}/deliver")
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'This package has already been delivered.');

        expect($coco->fresh()->quantity)->toBe('7.0000');
    });

    it('rolls back when the stock dropped after packaging', function () {
        [$coco, $morango] = seedDindins('10', '5');
        $id = createPackage([['id' => $coco->id, 'quantity' => 3], ['id' => $morango->id, 'quantity' => 4]])->json('data.id');
        $this->patchJson("/api/inventory-items/{$morango->id}", ['quantity' => 1])->assertOk();

        $this->postJson("/api/delivery-packages/{$id}/deliver")
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Estoque insuficiente de "Dindin de morango". Necessário: 4, Disponível: 1.');

        expect($coco->fresh()->quantity)->toBe('10.0000')
            ->and(DeliveryPackage::find($id)->isDelivered())->toBeFalse();
    });

    it('returns 404 for an unknown package', function () {
        $this->postJson('/api/delivery-packages/abc/deliver')
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Pacote não encontrado.');
    });

    it('returns 500 when the database fails', function () {
        $package = DeliveryPackage::factory()->create();
        failTable('delivery_package_has_item');

        $this->postJson("/api/delivery-packages/{$package->id}/deliver")
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error delivering package.');
    });
});

describe('DELETE /api/delivery-packages/{delivery_package_id}', function () {
    it('deletes the package and its items without touching the stock', function () {
        [$coco] = seedDindins('10');
        $id = createPackage([['id' => $coco->id, 'quantity' => 3]])->json('data.id');

        $this->deleteJson("/api/delivery-packages/{$id}")
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Pacote excluído com sucesso.')
            ->assertJsonPath('data', null);

        expect(DB::table('delivery_package_has_item')->count())->toBe(0)
            ->and(stockByName())->toBe(['Dindin de coco' => ['10.0000', '0.0000'], 'Dindin de morango' => ['5.0000', '0.0000']]);
    });

    it('returns 404 for an unknown package', function () {
        $this->deleteJson('/api/delivery-packages/404')
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Pacote não encontrado.');
    });

    it('returns 500 when the database fails', function () {
        failTable('delivery_packages');

        $this->deleteJson('/api/delivery-packages/1')
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error deleting package.');
    });
});
