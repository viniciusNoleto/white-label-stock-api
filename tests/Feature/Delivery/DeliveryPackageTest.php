<?php

use App\Models\Delivery\DeliveryPackage;
use App\Models\Inventory\Item;
use App\Models\Inventory\Product;
use App\Models\Inventory\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->withToken(accessToken());
});

/**
 * Coco a granel em dois lotes (6 vence em 10 dias, 4 vence em 3 dias) e bolos atômicos
 * (BOLO-0001 vence em 5 dias, BOLO-0002 em 2 dias, BOLO-0003 não vence).
 *
 * @return array{0: Product, 1: Product}
 */
function seedPackageStock(): array {
    $unit = Unit::factory()->create(['name' => 'Unidade', 'abbreviation' => 'un']);
    $coco = Product::factory()->bulk()->create(['name' => 'Coco ralado', 'unit_id' => $unit->id]);
    $cake = Product::factory()->create(['name' => 'Bolo', 'sku' => 'BOLO', 'unit_id' => $unit->id]);

    Item::factory()->create(['product_id' => $coco->id, 'code' => 'COCO-L1', 'quantity' => '6', 'expires_at' => now()->addDays(10)->toDateString()]);
    Item::factory()->create(['product_id' => $coco->id, 'code' => 'COCO-L2', 'quantity' => '4', 'expires_at' => now()->addDays(3)->toDateString()]);
    Item::factory()->create(['product_id' => $cake->id, 'code' => 'BOLO-0001', 'expires_at' => now()->addDays(5)->toDateString()]);
    Item::factory()->create(['product_id' => $cake->id, 'code' => 'BOLO-0002', 'expires_at' => now()->addDays(2)->toDateString()]);
    Item::factory()->create(['product_id' => $cake->id, 'code' => 'BOLO-0003']);

    return [$coco, $cake];
}

function postPackage(mixed $items, ?string $notes = null): TestResponse {
    return test()->postJson('/api/delivery-packages', ['items' => $items, 'notes' => $notes]);
}

/**
 * `[estoque, reservado]` por produto.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function productStock(): array {
    return collect(test()->getJson('/api/products')->json('data.items'))
        ->mapWithKeys(fn($product) => [$product['name'] => [$product['stock_quantity'], $product['reserved_quantity']]])
        ->all();
}

/**
 * @return array<string, array{0: string, 1: string}> `[quantidade, reservado]` por código
 */
function itemStock(): array {
    return collect(test()->getJson('/api/items')->json('data.items'))
        ->mapWithKeys(fn($item) => [$item['code'] => [$item['quantity'], $item['reserved_quantity']]])
        ->all();
}

describe('POST /api/delivery-packages', function () {
    it('creates a pending package reserving items first-expiring-first', function () {
        [$coco, $cake] = seedPackageStock();

        $response = postPackage([['product_id' => $coco->id, 'quantity' => 7], ['product_id' => (string)$cake->id, 'quantity' => '2']], "Rua A, 10\nEntregar após 14h");

        $response->assertCreated()
            ->assertJsonPath('message.pt-br', 'Pacote criado com sucesso.')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.notes', "Rua A, 10\nEntregar após 14h")
            ->assertJsonPath('data.delivered_at', null);

        $l1 = Item::where('code', 'COCO-L1')->value('id');
        $l2 = Item::where('code', 'COCO-L2')->value('id');
        $b1 = Item::where('code', 'BOLO-0001')->value('id');
        $b2 = Item::where('code', 'BOLO-0002')->value('id');

        expect(array_keys($response->json('data')))->toBe(['id', 'status', 'notes', 'delivered_at', 'created_at', 'updated_at', 'items'])
            ->and($response->json('data.items'))->toBe([
                [
                    'product_id' => $cake->id, 'name' => 'Bolo', 'is_atomic' => true, 'quantity' => '2.0000', 'stock_quantity' => '3.0000',
                    'unit' => ['id' => $cake->unit_id, 'name' => 'Unidade', 'abbreviation' => 'un'],
                    'allocations' => [
                        ['item_id' => $b2, 'code' => 'BOLO-0002', 'quantity' => '1.0000'],
                        ['item_id' => $b1, 'code' => 'BOLO-0001', 'quantity' => '1.0000'],
                    ],
                ],
                [
                    'product_id' => $coco->id, 'name' => 'Coco ralado', 'is_atomic' => false, 'quantity' => '7.0000', 'stock_quantity' => '10.0000',
                    'unit' => ['id' => $coco->unit_id, 'name' => 'Unidade', 'abbreviation' => 'un'],
                    'allocations' => [
                        ['item_id' => $l2, 'code' => 'COCO-L2', 'quantity' => '4.0000'],
                        ['item_id' => $l1, 'code' => 'COCO-L1', 'quantity' => '3.0000'],
                    ],
                ],
            ]);
    });

    it('does not subtract the stock but shows it as reserved', function () {
        [$coco, $cake] = seedPackageStock();

        postPackage([['product_id' => $coco->id, 'quantity' => 3], ['product_id' => $cake->id, 'quantity' => 1]])->assertCreated();
        postPackage([['product_id' => $coco->id, 'quantity' => 2.5]])->assertCreated();

        expect(productStock())->toBe([
            'Bolo' => ['3.0000', '1.0000'],
            'Coco ralado' => ['10.0000', '5.5000'],
        ])->and(itemStock())->toBe([
            'BOLO-0002' => ['1.0000', '1.0000'],
            'BOLO-0001' => ['1.0000', '0.0000'],
            'BOLO-0003' => ['1.0000', '0.0000'],
            'COCO-L2' => ['4.0000', '4.0000'],
            'COCO-L1' => ['6.0000', '1.5000'],
        ]);
    });

    it('stores blank notes as null', function () {
        [$coco] = seedPackageStock();

        postPackage([['product_id' => $coco->id, 'quantity' => 1]], "   \n  ")->assertJsonPath('data.notes', null);
    });

    it('rejects packaging more than the free stock and creates nothing', function () {
        [$coco, $cake] = seedPackageStock();
        postPackage([['product_id' => $coco->id, 'quantity' => 7]])->assertCreated();

        postPackage([['product_id' => $cake->id, 'quantity' => 1], ['product_id' => $coco->id, 'quantity' => 4]])
            ->assertUnprocessable()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message.pt-br', 'Estoque livre insuficiente de "Coco ralado" para empacotar. Necessário: 4, Disponível: 3.');

        expect(DeliveryPackage::count())->toBe(1)
            ->and(DB::table('delivery_package_has_item')->where('product_id', $cake->id)->count())->toBe(0);
    });

    it('does not reserve expired items', function () {
        [, $cake] = seedPackageStock();
        Item::where('code', 'BOLO-0002')->update(['expires_at' => now()->subDay()->toDateString()]);

        postPackage([['product_id' => $cake->id, 'quantity' => 3]])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Estoque livre insuficiente de "Bolo" para empacotar. Necessário: 3, Disponível: 2.');

        postPackage([['product_id' => $cake->id, 'quantity' => 1]])->assertJsonPath('data.items.0.allocations.0.code', 'BOLO-0001');

        expect(productStock()['Bolo'])->toBe(['3.0000', '1.0000']);
    });

    it('only packages whole units of an atomic product', function () {
        [, $cake] = seedPackageStock();

        postPackage([['product_id' => $cake->id, 'quantity' => 1.5]])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', '"Bolo" é contado por unidade: use uma quantidade inteira.');
    });

    it('packages exactly the atomic items chosen one by one instead of first-expiring-first', function () {
        [$coco, $cake] = seedPackageStock();
        $b1 = Item::where('code', 'BOLO-0001')->value('id');
        $b3 = Item::where('code', 'BOLO-0003')->value('id');

        $response = postPackage([['product_id' => $cake->id, 'quantity' => 2, 'item_ids' => [$b3, $b1]], ['product_id' => $coco->id, 'quantity' => 1]]);

        $response->assertCreated();

        expect(collect($response->json('data.items.0.allocations'))->pluck('code')->all())->toBe(['BOLO-0001', 'BOLO-0003'])
            ->and(itemStock())->toMatchArray([
                'BOLO-0002' => ['1.0000', '0.0000'],
                'BOLO-0001' => ['1.0000', '1.0000'],
                'BOLO-0003' => ['1.0000', '1.0000'],
            ]);
    });

    it('rejects a chosen item that is not available and creates nothing', function (string $case, string $message) {
        [$coco, $cake] = seedPackageStock();
        $b2 = Item::where('code', 'BOLO-0002')->value('id');
        $l1 = Item::where('code', 'COCO-L1')->value('id');

        $items = match ($case) {
            'reserved' => (function () use ($cake, $b2) {
                postPackage([['product_id' => $cake->id, 'quantity' => 1]])->assertCreated();

                return [['product_id' => $cake->id, 'quantity' => 1, 'item_ids' => [$b2]]];
            })(),
            'expired' => (function () use ($cake, $b2) {
                Item::whereKey($b2)->update(['expires_at' => now()->subDay()->toDateString()]);

                return [['product_id' => $cake->id, 'quantity' => 1, 'item_ids' => [$b2]]];
            })(),
            'other product' => [['product_id' => $cake->id, 'quantity' => 1, 'item_ids' => [$l1]]],
            'unknown' => [['product_id' => $cake->id, 'quantity' => 1, 'item_ids' => [999]]],
            'count' => [['product_id' => $cake->id, 'quantity' => 2, 'item_ids' => [$b2]]],
            'bulk' => [['product_id' => $coco->id, 'quantity' => 1, 'item_ids' => [$l1]]],
            'invalid ids' => [['product_id' => $cake->id, 'quantity' => 2, 'item_ids' => [$b2, $b2]]],
        };

        $before = DeliveryPackage::count();

        postPackage($items)
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', $message);

        expect(DeliveryPackage::count())->toBe($before);
    })->with([
        ['reserved', 'Item BOLO-0002 is already reserved in another pending package.'],
        ['expired', 'Item BOLO-0002 is expired and cannot be packaged.'],
        ['other product', 'A selected item does not exist or is not from "Bolo".'],
        ['unknown', 'A selected item does not exist or is not from "Bolo".'],
        ['count', 'The quantity of "Bolo" (2) does not match the selected items (1).'],
        ['bulk', 'Only products counted by unit allow choosing the items: "Coco ralado" is bulk.'],
        ['invalid ids', 'Invalid selected items.'],
    ]);

    it('rejects invalid items', function (mixed $items) {
        seedPackageStock();

        postPackage($items)
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Select at least one product, each with a quantity greater than zero.');
    })->with([
        'missing' => [null],
        'empty' => [[]],
        'not a list' => ['x'],
        'no product' => [[['quantity' => 1]]],
        'invalid product' => [[['product_id' => 'abc', 'quantity' => 1]]],
    ]);

    it('rejects an invalid quantity', function (mixed $quantity) {
        [$coco] = seedPackageStock();

        postPackage([['product_id' => $coco->id, 'quantity' => $quantity]])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Select at least one product, each with a quantity greater than zero.');
    })->with([null, 0, -1, 'abc']);

    it('rejects the same product twice', function () {
        [$coco] = seedPackageStock();

        postPackage([['product_id' => $coco->id, 'quantity' => 1], ['product_id' => $coco->id, 'quantity' => 2]])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Each product can appear only once in the package.');
    });

    it('rejects notes that are too long', function () {
        [$coco] = seedPackageStock();

        postPackage([['product_id' => $coco->id, 'quantity' => 1]], str_repeat('a', 10001))
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Notes must be a text of up to 10000 characters.');
    });

    it('returns 422 for a product that does not exist and creates nothing', function () {
        [$coco] = seedPackageStock();

        postPackage([['product_id' => $coco->id, 'quantity' => 1], ['product_id' => 999, 'quantity' => 1]])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Some of the given products do not exist.');

        expect(DeliveryPackage::count())->toBe(0);
    });

    it('returns 500 when the database fails', function () {
        [$coco] = seedPackageStock();
        failTable('delivery_packages');

        postPackage([['product_id' => $coco->id, 'quantity' => 1]])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error creating package.');
    });
});

describe('GET /api/delivery-packages', function () {
    it('lists the most recent first, filters by status and paginates', function () {
        [$coco] = seedPackageStock();
        $old = DeliveryPackage::factory()->delivered()->create(['created_at' => now()->subDay()]);
        $new = postPackage([['product_id' => $coco->id, 'quantity' => 2]], 'Maria')->json('data.id');

        $all = $this->getJson('/api/delivery-packages')->assertOk()->json('data');

        expect(collect($all['items'])->pluck('id')->all())->toBe([$new, $old->id])
            ->and($all['items'][0]['items'][0])->toMatchArray(['product_id' => $coco->id, 'quantity' => '2.0000'])
            ->and($all['meta'])->toBe(['page' => 1, 'per_page' => 8, 'total' => 2, 'last_page' => 1])
            ->and(collect($this->getJson('/api/delivery-packages?status=delivered')->json('data.items'))->pluck('id')->all())->toBe([$old->id])
            ->and(collect($this->getJson('/api/delivery-packages?status=PENDING')->json('data.items'))->pluck('id')->all())->toBe([$new])
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
    it('replaces notes and items, counting its own reservation as free', function () {
        [$coco, $cake] = seedPackageStock();
        $id = postPackage([['product_id' => $coco->id, 'quantity' => 8]], 'Antes')->json('data.id');

        $this->putJson("/api/delivery-packages/{$id}", ['items' => [['product_id' => $coco->id, 'quantity' => 10], ['product_id' => $cake->id, 'quantity' => 3]], 'notes' => 'Depois'])
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Pacote atualizado com sucesso.')
            ->assertJsonPath('data.notes', 'Depois')
            ->assertJsonPath('data.items.0.quantity', '3.0000')
            ->assertJsonPath('data.items.1.quantity', '10.0000');

        $this->putJson("/api/delivery-packages/{$id}", ['items' => [['product_id' => $cake->id, 'quantity' => 1]]])
            ->assertOk()
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.items.0.allocations.0.code', 'BOLO-0002');

        expect(productStock())->toBe([
            'Bolo' => ['3.0000', '1.0000'],
            'Coco ralado' => ['10.0000', '0.0000'],
        ]);
    });

    it('keeps or swaps the chosen items when editing, counting its own items as free', function () {
        [, $cake] = seedPackageStock();
        $b1 = Item::where('code', 'BOLO-0001')->value('id');
        $b3 = Item::where('code', 'BOLO-0003')->value('id');
        $id = postPackage([['product_id' => $cake->id, 'quantity' => 1, 'item_ids' => [$b3]]])->json('data.id');

        $this->putJson("/api/delivery-packages/{$id}", ['items' => [['product_id' => $cake->id, 'quantity' => 2, 'item_ids' => [$b3, $b1]]]])
            ->assertOk()
            ->assertJsonPath('data.items.0.allocations.0.code', 'BOLO-0001')
            ->assertJsonPath('data.items.0.allocations.1.code', 'BOLO-0003');

        expect(productStock()['Bolo'])->toBe(['3.0000', '2.0000']);
    });

    it('rejects more than the free stock and keeps the package', function () {
        [$coco] = seedPackageStock();
        postPackage([['product_id' => $coco->id, 'quantity' => 6]]);
        $id = postPackage([['product_id' => $coco->id, 'quantity' => 1]], 'Original')->json('data.id');

        $this->putJson("/api/delivery-packages/{$id}", ['items' => [['product_id' => $coco->id, 'quantity' => 5]], 'notes' => 'Novo'])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Not enough free stock of "Coco ralado" to package. Required: 5, Available: 4.');

        $this->getJson("/api/delivery-packages/{$id}")
            ->assertJsonPath('data.notes', 'Original')
            ->assertJsonPath('data.items.0.quantity', '1.0000');
    });

    it('does not edit a delivered package', function () {
        [$coco] = seedPackageStock();
        $package = DeliveryPackage::factory()->delivered()->create();

        $this->putJson("/api/delivery-packages/{$package->id}", ['items' => [['product_id' => $coco->id, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Este pacote já foi entregue.');
    });

    it('returns 404 for an unknown package', function () {
        [$coco] = seedPackageStock();

        $this->putJson('/api/delivery-packages/404', ['items' => [['product_id' => $coco->id, 'quantity' => 1]]])
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Pacote não encontrado.');
    });

    it('returns 500 when the database fails', function () {
        [$coco] = seedPackageStock();
        $package = DeliveryPackage::factory()->create();
        failTable('delivery_package_has_item');

        $this->putJson("/api/delivery-packages/{$package->id}", ['items' => [['product_id' => $coco->id, 'quantity' => 1]]])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error updating package.');
    });
});

describe('POST /api/delivery-packages/{delivery_package_id}/deliver', function () {
    it('removes atomic items, subtracts bulk lots and keeps the delivered codes', function () {
        [$coco, $cake] = seedPackageStock();
        $id = postPackage([['product_id' => $coco->id, 'quantity' => 7], ['product_id' => $cake->id, 'quantity' => 2]])->json('data.id');
        postPackage([['product_id' => $coco->id, 'quantity' => 1]]);

        $this->postJson("/api/delivery-packages/{$id}/deliver")
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Pacote entregue. Os itens foram subtraídos do estoque.')
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.items.0.stock_quantity', '1.0000')
            ->assertJsonPath('data.items.0.allocations.0', ['item_id' => null, 'code' => 'BOLO-0002', 'quantity' => '1.0000'])
            ->assertJsonPath('data.items.1.stock_quantity', '3.0000');

        expect(DeliveryPackage::find($id)->delivered_at)->not->toBeNull()
            ->and(itemStock())->toBe([
                'BOLO-0003' => ['1.0000', '0.0000'],
                'COCO-L2' => ['0.0000', '0.0000'],
                'COCO-L1' => ['3.0000', '1.0000'],
            ]);
    });

    it('does not deliver twice', function () {
        [$coco] = seedPackageStock();
        $id = postPackage([['product_id' => $coco->id, 'quantity' => 3]])->json('data.id');
        $this->postJson("/api/delivery-packages/{$id}/deliver")->assertOk();

        $this->postJson("/api/delivery-packages/{$id}/deliver")
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'This package has already been delivered.');

        expect(productStock()['Coco ralado'])->toBe(['7.0000', '0.0000']);
    });

    it('rolls back when a reserved lot no longer has the quantity', function () {
        [$coco, $cake] = seedPackageStock();
        $id = postPackage([['product_id' => $cake->id, 'quantity' => 1], ['product_id' => $coco->id, 'quantity' => 4]])->json('data.id');
        DB::table('items')->where('code', 'COCO-L2')->update(['quantity' => '1']);

        $this->postJson("/api/delivery-packages/{$id}/deliver")
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Estoque insuficiente de "Coco ralado". Necessário: 4, Disponível: 1.');

        expect(Item::where('code', 'BOLO-0002')->exists())->toBeTrue()
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
    it('deletes the package and frees the reservation without touching the stock', function () {
        [$coco] = seedPackageStock();
        $id = postPackage([['product_id' => $coco->id, 'quantity' => 3]])->json('data.id');

        $this->deleteJson("/api/delivery-packages/{$id}")
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Pacote excluído com sucesso.')
            ->assertJsonPath('data', null);

        expect(DB::table('delivery_package_has_item')->count())->toBe(0)
            ->and(productStock())->toBe(['Bolo' => ['3.0000', '0.0000'], 'Coco ralado' => ['10.0000', '0.0000']]);
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
