<?php

use App\Models\Delivery\DeliveryPackage;
use App\Models\Delivery\DeliveryPackageLine;
use App\Models\Inventory\Item;
use App\Models\Inventory\Product;
use App\Models\Inventory\Storage;
use App\Models\Inventory\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->withToken(accessToken());
    Carbon::setTestNow('2026-11-09 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function generateItems(array $payload): TestResponse {
    return test()->postJson('/api/items', $payload);
}

function listStockItems(string $query = ''): TestResponse {
    return test()->getJson("/api/items{$query}");
}

function reserve(Item $item, string $quantity = '1'): void {
    DeliveryPackageLine::create([
        'delivery_package_id' => DeliveryPackage::factory()->create()->id,
        'product_id' => $item->product_id,
        'item_id' => $item->id,
        'item_code' => $item->code,
        'quantity' => $quantity,
    ]);
}

describe('POST /api/items', function () {
    it('generates one atomic item with an automatic code, produced today', function () {
        $product = Product::factory()->create(['sku' => 'ARM-A']);

        $response = generateItems(['product_id' => $product->id]);

        $response->assertCreated()
            ->assertJsonPath('message.pt-br', '1 item(ns) gerado(s) com sucesso.')
            ->assertJsonCount(1, 'data.items');

        expect(array_keys($response->json('data.items.0')))->toBe(['id', 'code', 'quantity', 'reserved_quantity', 'produced_at', 'expires_at', 'created_at', 'updated_at', 'product', 'storage'])
            ->and($response->json('data.items.0'))->toMatchArray([
                'code' => 'ARM-A-0001',
                'quantity' => '1.0000',
                'reserved_quantity' => '0.0000',
                'produced_at' => '2026-11-09',
                'expires_at' => null,
                'storage' => null,
            ])
            ->and($response->json('data.items.0.product'))->toMatchArray(['id' => $product->id, 'sku' => 'ARM-A', 'is_atomic' => true]);
    });

    it('generates a batch of atomic items, each with its own code and expiration from the shelf life', function () {
        $storage = Storage::factory()->create();
        $cake = Product::factory()->create(['name' => 'Bolo', 'sku' => null, 'shelf_life_days' => 5]);

        $response = generateItems(['product_id' => $cake->id, 'quantity' => 8, 'produced_at' => '2026-11-07', 'storage_id' => $storage->id])
            ->assertCreated()
            ->assertJsonPath('message.en-us', '8 item(s) generated successfully.');

        $items = collect($response->json('data.items'));

        expect($items->pluck('code')->all())->toBe(collect(range(1, 8))->map(fn($n) => sprintf('P%d-%04d', $cake->id, $n))->all())
            ->and($items->pluck('expires_at')->unique()->all())->toBe(['2026-11-12'])
            ->and($items->pluck('produced_at')->unique()->all())->toBe(['2026-11-07'])
            ->and($items->pluck('storage.id')->unique()->all())->toBe([$storage->id]);

        generateItems(['product_id' => $cake->id, 'quantity' => 2])->assertJsonPath('data.items.1.code', sprintf('P%d-0010', $cake->id));
    });

    it('skips codes already used manually', function () {
        $product = Product::factory()->create(['sku' => 'X']);
        generateItems(['product_id' => $product->id, 'code' => 'X-0002'])->assertCreated();

        $codes = collect(generateItems(['product_id' => $product->id, 'quantity' => 2])->json('data.items'))->pluck('code')->all();

        expect($codes)->toBe(['X-0001', 'X-0003']);
    });

    it('generates one bulk lot with the quantity', function () {
        $screw = Product::factory()->bulk()->create(['sku' => 'PAR']);

        generateItems(['product_id' => $screw->id, 'quantity' => 1500.5])
            ->assertCreated()
            ->assertJsonPath('message.pt-br', '1 item(ns) gerado(s) com sucesso.')
            ->assertJsonPath('data.items.0.code', 'PAR-0001')
            ->assertJsonPath('data.items.0.quantity', '1500.5000');
    });

    it('rejects a manual code for a batch and a repeated code', function () {
        $product = Product::factory()->create();
        Item::factory()->create(['code' => 'DUP']);

        generateItems(['product_id' => $product->id, 'quantity' => 2, 'code' => 'A1'])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'A manual code can only be used when generating a single item.');

        generateItems(['product_id' => $product->id, 'code' => 'DUP'])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Já existe um item com este código.');
    });

    it('only generates whole batches of atomic items, up to the limit', function (mixed $quantity) {
        generateItems(['product_id' => Product::factory()->create()->id, 'quantity' => $quantity])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Itens atômicos são gerados em quantidade inteira, de 1 a 500 por vez.');
    })->with([1.5, 501]);

    it('validates the payload', function (array $payload, string $message) {
        generateItems($payload)
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', $message);
    })->with([
        'no product' => [[], 'Select the product.'],
        'zero quantity' => [['product_id' => 1, 'quantity' => 0], 'Invalid quantity.'],
        'bad date' => [['product_id' => 1, 'produced_at' => '09/11/2026'], 'Invalid date. Use the YYYY-MM-DD format.'],
    ]);

    it('returns 422 for references that do not exist', function (bool $unknownProduct) {
        $product = Product::factory()->create();

        generateItems($unknownProduct ? ['product_id' => 999] : ['product_id' => $product->id, 'storage_id' => 999])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'The given product or storage does not exist.');

        expect(Item::count())->toBe(0);
    })->with([true, false]);

    it('consumes the recipe first-expiring-first when producing', function () {
        $unit = Unit::factory()->create();
        $flour = Product::factory()->bulk()->create(['name' => 'Farinha', 'unit_id' => $unit->id]);
        $egg = Product::factory()->create(['name' => 'Ovo', 'unit_id' => $unit->id]);
        $cake = Product::factory()->create(['name' => 'Bolo', 'unit_id' => $unit->id, 'shelf_life_days' => 3]);
        $cake->components()->attach([$flour->id => ['quantity_required' => '0.5'], $egg->id => ['quantity_required' => '3']]);

        Item::factory()->create(['product_id' => $flour->id, 'code' => 'FAR-NEW', 'quantity' => '5', 'expires_at' => '2027-01-01']);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'FAR-OLD', 'quantity' => '2', 'expires_at' => '2026-12-01']);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'FAR-NONE', 'quantity' => '10']);
        $eggs = collect(range(1, 7))->map(fn($n) => Item::factory()->create([
            'product_id' => $egg->id,
            'code' => "OVO-{$n}",
            'expires_at' => Carbon::parse('2026-11-20')->addDays($n)->toDateString(),
        ]));
        reserve($eggs[0]);

        generateItems(['product_id' => $cake->id, 'quantity' => 2, 'consume_components' => true])->assertCreated();

        // Farinha: 1 kg sai do lote que vence primeiro. Ovos: 6, pulando o reservado (OVO-1).
        expect(Item::where('product_id', $flour->id)->pluck('quantity', 'code')->all())->toBe(['FAR-NEW' => '5.0000', 'FAR-OLD' => '1.0000', 'FAR-NONE' => '10.0000'])
            ->and(Item::where('product_id', $egg->id)->pluck('code')->all())->toBe(['OVO-1'])
            ->and(Item::where('product_id', $cake->id)->count())->toBe(2);
    });

    it('takes from several lots when one is not enough', function () {
        $flour = Product::factory()->bulk()->create();
        $bread = Product::factory()->create();
        $bread->components()->attach($flour->id, ['quantity_required' => '3']);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'L1', 'quantity' => '2', 'expires_at' => '2026-12-01']);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'L2', 'quantity' => '5', 'expires_at' => '2026-12-05']);

        generateItems(['product_id' => $bread->id, 'consume_components' => true])->assertCreated();

        expect(Item::where('product_id', $flour->id)->pluck('quantity', 'code')->all())->toBe(['L1' => '0.0000', 'L2' => '4.0000']);
    });

    it('never consumes expired stock', function () {
        $flour = Product::factory()->bulk()->create(['name' => 'Farinha']);
        $bread = Product::factory()->create();
        $bread->components()->attach($flour->id, ['quantity_required' => '3']);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'OLD', 'quantity' => '5', 'expires_at' => '2026-11-08']);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'NEW', 'quantity' => '2', 'expires_at' => '2026-12-01']);

        generateItems(['product_id' => $bread->id, 'consume_components' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Estoque insuficiente de "Farinha". Necessário: 3, Disponível: 2.');

        Item::factory()->create(['product_id' => $flour->id, 'code' => 'TODAY', 'quantity' => '1', 'expires_at' => '2026-11-09']);

        generateItems(['product_id' => $bread->id, 'consume_components' => true])->assertCreated();

        expect(Item::where('product_id', $flour->id)->pluck('quantity', 'code')->all())->toBe(['OLD' => '5.0000', 'NEW' => '0.0000', 'TODAY' => '0.0000']);
    });

    it('rolls everything back when a component is missing', function () {
        $screw = Product::factory()->bulk()->create(['name' => 'Parafuso']);
        $board = Product::factory()->create(['name' => 'Chapa']);
        $cabinet = Product::factory()->create();
        $cabinet->components()->attach([$screw->id => ['quantity_required' => '100'], $board->id => ['quantity_required' => '4']]);
        Item::factory()->create(['product_id' => $screw->id, 'quantity' => '500']);
        Item::factory()->count(5)->create(['product_id' => $board->id]);

        generateItems(['product_id' => $cabinet->id, 'quantity' => 2, 'consume_components' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Estoque insuficiente de "Chapa". Necessário: 8, Disponível: 5.');

        expect(Item::where('product_id', $screw->id)->value('quantity'))->toBe('500.0000')
            ->and(Item::where('product_id', $board->id)->count())->toBe(5)
            ->and(Item::where('product_id', $cabinet->id)->count())->toBe(0)
            ->and($cabinet->fresh()->next_item_number)->toBe(1);
    });

    it('does not consume without a recipe', function () {
        generateItems(['product_id' => Product::factory()->create()->id, 'consume_components' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'This product has no recipe to consume.');
    });

    it('returns 500 when the database fails', function () {
        $product = Product::factory()->create();
        failTable('items');

        generateItems(['product_id' => $product->id])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error generating items.');
    });
});

describe('GET /api/items', function () {
    it('lists items by product, first-expiring-first, with reservation', function () {
        $unit = Unit::factory()->create(['abbreviation' => 'un']);
        $storage = Storage::factory()->create(['name' => 'Vitrine']);
        $cake = Product::factory()->create(['name' => 'Bolo', 'sku' => 'BOLO', 'unit_id' => $unit->id]);
        $acai = Product::factory()->bulk()->create(['name' => 'Açaí', 'unit_id' => $unit->id]);
        $late = Item::factory()->create(['product_id' => $cake->id, 'code' => 'B-2', 'expires_at' => '2026-11-15', 'storage_id' => $storage->id]);
        Item::factory()->create(['product_id' => $cake->id, 'code' => 'B-1', 'expires_at' => '2026-11-10']);
        Item::factory()->create(['product_id' => $cake->id, 'code' => 'B-3']);
        Item::factory()->create(['product_id' => $acai->id, 'code' => 'ACAI-1', 'quantity' => '3.5']);
        reserve($late);

        $response = listStockItems()->assertOk();

        expect(collect($response->json('data.items'))->pluck('code')->all())->toBe(['ACAI-1', 'B-1', 'B-2', 'B-3'])
            ->and($response->json('data.items.2'))->toMatchArray([
                'code' => 'B-2',
                'reserved_quantity' => '1.0000',
                'expires_at' => '2026-11-15',
                'storage' => ['id' => $storage->id, 'name' => 'Vitrine'],
            ])
            ->and($response->json('data.items.2.product'))->toBe([
                'id' => $cake->id, 'name' => 'Bolo', 'sku' => 'BOLO', 'is_atomic' => true,
                'unit' => ['id' => $unit->id, 'name' => $unit->name, 'abbreviation' => 'un'],
            ]);
    });

    it('filters by search, product, storage and expiration', function () {
        $storage = Storage::factory()->create();
        $cake = Product::factory()->create(['name' => 'Bolo de chocolate', 'sku' => 'CHOC']);
        $pie = Product::factory()->create(['name' => 'Torta']);
        Item::factory()->create(['product_id' => $cake->id, 'code' => 'EXPIRED', 'expires_at' => '2026-11-08']);
        Item::factory()->create(['product_id' => $cake->id, 'code' => 'TODAY', 'expires_at' => '2026-11-09', 'storage_id' => $storage->id]);
        Item::factory()->create(['product_id' => $pie->id, 'code' => 'WEEK', 'expires_at' => '2026-11-16']);
        Item::factory()->create(['product_id' => $pie->id, 'code' => 'LATER', 'expires_at' => '2026-11-17']);
        Item::factory()->create(['product_id' => $pie->id, 'code' => 'NEVER']);

        $codes = fn(string $query) => collect(listStockItems($query)->json('data.items'))->pluck('code')->all();

        expect($codes('?search=choc'))->toBe(['EXPIRED', 'TODAY'])
            ->and($codes('?search=week'))->toBe(['WEEK'])
            ->and($codes("?product_id={$pie->id}"))->toBe(['WEEK', 'LATER', 'NEVER'])
            ->and($codes("?storage_id={$storage->id}"))->toBe(['TODAY'])
            ->and($codes('?expiration=expired'))->toBe(['EXPIRED'])
            ->and($codes('?expiration=expiring'))->toBe(['TODAY', 'WEEK'])
            ->and($codes('?expiration=valid'))->toBe(['TODAY', 'WEEK', 'LATER', 'NEVER'])
            ->and(count($codes('?expiration=xyz&product_id=abc')))->toBe(5);
    });

    it('returns 500 when the database fails', function () {
        failTable('items');

        listStockItems()
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error fetching items.');
    });
});

describe('PATCH /api/items/{item_id}', function () {
    it('updates code, storage and dates; a new production date recalculates the expiration', function () {
        $storage = Storage::factory()->create();
        $product = Product::factory()->create(['shelf_life_days' => 10]);
        $item = Item::factory()->create(['product_id' => $product->id, 'code' => 'OLD', 'produced_at' => '2026-11-01', 'expires_at' => '2026-11-11']);

        $this->patchJson("/api/items/{$item->id}", ['code' => ' NEW ', 'storage_id' => $storage->id, 'produced_at' => '2026-11-05'])
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Item atualizado com sucesso.')
            ->assertJsonPath('data.code', 'NEW')
            ->assertJsonPath('data.storage.id', $storage->id)
            ->assertJsonPath('data.produced_at', '2026-11-05')
            ->assertJsonPath('data.expires_at', '2026-11-15');

        $this->patchJson("/api/items/{$item->id}", ['expires_at' => null, 'storage_id' => null])
            ->assertOk()
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.storage', null);

        $this->patchJson("/api/items/{$item->id}", ['produced_at' => '2026-11-06', 'expires_at' => '2026-12-25'])
            ->assertJsonPath('data.expires_at', '2026-12-25');
    });

    it('sets, adds and subtracts the quantity of a bulk lot', function () {
        $item = Item::factory()->create(['product_id' => Product::factory()->bulk()->create()->id, 'quantity' => '10']);

        $this->patchJson("/api/items/{$item->id}", ['quantity_add' => 2.5])
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Quantidade atualizada com sucesso.')
            ->assertJsonPath('data.quantity', '12.5000');
        $this->patchJson("/api/items/{$item->id}", ['quantity_subtract' => 0.5])->assertJsonPath('data.quantity', '12.0000');
        $this->patchJson("/api/items/{$item->id}", ['quantity' => 0])->assertJsonPath('data.quantity', '0.0000');

        $this->patchJson("/api/items/{$item->id}", ['quantity_subtract' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', "Insufficient stock of \"{$item->code}\". Required: 1, Available: 0.");
    });

    it('does not go below what is reserved', function () {
        $item = Item::factory()->create(['product_id' => Product::factory()->bulk()->create()->id, 'quantity' => '10']);
        reserve($item, '4');

        $this->patchJson("/api/items/{$item->id}", ['quantity' => 3])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'A quantidade não pode ficar abaixo do reservado em pacotes de entrega (4).');

        expect($item->fresh()->quantity)->toBe('10.0000');
    });

    it('does not change the quantity of an atomic item', function () {
        $item = Item::factory()->create();

        $this->patchJson("/api/items/{$item->id}", ['quantity' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'An atomic item is a single unit: to remove it from stock, delete the item.');
    });

    it('validates the payload', function (array $payload, string $message) {
        $item = Item::factory()->create(['product_id' => Product::factory()->bulk()->create()->id]);

        $this->patchJson("/api/items/{$item->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', $message);
    })->with([
        'nothing' => [[], 'No fields to update were provided.'],
        'two modes' => [['quantity' => 1, 'quantity_add' => 1], 'Provide only one quantity mode: set, add or subtract.'],
        'negative' => [['quantity' => -1], 'Invalid quantity.'],
        'blank code' => [['code' => ''], 'Invalid code (up to 80 characters).'],
        'bad date' => [['expires_at' => '2026-13-01'], 'Invalid date. Use the YYYY-MM-DD format.'],
    ]);

    it('rejects a repeated code and an unknown storage', function () {
        Item::factory()->create(['code' => 'TAKEN']);
        $item = Item::factory()->create();

        $this->patchJson("/api/items/{$item->id}", ['code' => 'TAKEN'])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'An item with this code already exists.');

        $this->patchJson("/api/items/{$item->id}", ['storage_id' => 999])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'The given product or storage does not exist.');
    });

    it('returns 404 for an unknown item', function (string $id) {
        $this->patchJson("/api/items/{$id}", ['code' => 'X'])
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Item não encontrado.');
    })->with(['404', 'abc']);

    it('returns 500 when the database fails', function () {
        $item = Item::factory()->create();
        failTable('delivery_package_has_item');

        $this->patchJson("/api/items/{$item->id}", ['code' => 'X'])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error updating the item.');
    });
});

describe('DELETE /api/items/{item_id}', function () {
    it('deletes an item', function () {
        $item = Item::factory()->create();

        $this->deleteJson("/api/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Item excluído com sucesso.');

        expect(Item::find($item->id))->toBeNull();
    });

    it('returns 409 for an item reserved in a pending package', function () {
        $item = Item::factory()->create();
        reserve($item);

        $this->deleteJson("/api/items/{$item->id}")
            ->assertStatus(409)
            ->assertJsonPath('message.en-us', 'This item is reserved in a pending delivery package. Remove it from the package first.');
    });

    it('returns 404 for an unknown item', function () {
        $this->deleteJson('/api/items/abc')
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Item não encontrado.');
    });

    it('returns 500 when the database fails', function () {
        failTable('items');

        $this->deleteJson('/api/items/1')
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error deleting the item.');
    });
});
