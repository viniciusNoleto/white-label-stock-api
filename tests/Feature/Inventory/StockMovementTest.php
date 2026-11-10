<?php

use App\Models\Delivery\DeliveryPackage;
use App\Models\Delivery\DeliveryPackageLine;
use App\Models\Inventory\Item;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Storage;
use App\Models\Inventory\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Ana']);
    travelTo('2026-11-10 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Muda o relógio e emite um token novo (o access token vale 15 min).
 */
function travelTo(string $now): void {
    Carbon::setTestNow($now);
    test()->withToken(accessToken(test()->user));
}

function listMovements(string $query = ''): TestResponse {
    return test()->getJson("/api/stock-movements{$query}");
}

/**
 * @return array<int, array<string, mixed>>
 */
function movementRows(): array {
    return StockMovement::query()->orderBy('id')->get()
        ->map(fn(StockMovement $movement) => [
            'type' => $movement->type->value,
            'item_code' => $movement->item_code,
            'quantity' => $movement->quantity,
            'balance' => $movement->balance,
        ])->all();
}

describe('recording', function () {
    it('records an entry for each generated item, with the author and a snapshot of the names', function () {
        $storage = Storage::factory()->create(['name' => 'Depósito']);
        $unit = Unit::factory()->create(['abbreviation' => 'un']);
        $product = Product::factory()->create(['name' => 'Armário', 'sku' => 'ARM', 'unit_id' => $unit->id]);

        $this->postJson('/api/items', ['product_id' => $product->id, 'quantity' => 2, 'storage_id' => $storage->id])->assertCreated();

        expect(movementRows())->toBe([
            ['type' => 'entry', 'item_code' => 'ARM-0001', 'quantity' => '1.0000', 'balance' => '1.0000'],
            ['type' => 'entry', 'item_code' => 'ARM-0002', 'quantity' => '1.0000', 'balance' => '1.0000'],
        ])->and(StockMovement::first()->only(['product_id', 'product_name', 'unit_abbreviation', 'storage_id', 'storage_name', 'user_id', 'user_name']))->toBe([
            'product_id' => $product->id,
            'product_name' => 'Armário',
            'unit_abbreviation' => 'un',
            'storage_id' => $storage->id,
            'storage_name' => 'Depósito',
            'user_id' => $this->user->id,
            'user_name' => 'Ana',
        ]);
    });

    it('records the production and the consumption of the recipe', function () {
        $flour = Product::factory()->bulk()->create(['name' => 'Farinha']);
        $egg = Product::factory()->create(['name' => 'Ovo']);
        $cake = Product::factory()->create(['name' => 'Bolo', 'sku' => 'BOLO']);
        $cake->components()->attach([$flour->id => ['quantity_required' => '0.5'], $egg->id => ['quantity_required' => '1']]);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'FAR-1', 'quantity' => '3']);
        $eggItem = Item::factory()->create(['product_id' => $egg->id, 'code' => 'OVO-1']);

        $this->postJson('/api/items', ['product_id' => $cake->id, 'consume_components' => true])->assertCreated();

        expect(movementRows())->toBe([
            ['type' => 'consumption', 'item_code' => 'FAR-1', 'quantity' => '-0.5000', 'balance' => '2.5000'],
            ['type' => 'consumption', 'item_code' => 'OVO-1', 'quantity' => '-1.0000', 'balance' => '0.0000'],
            ['type' => 'production', 'item_code' => 'BOLO-0001', 'quantity' => '1.0000', 'balance' => '1.0000'],
        ])
            // O ovo (atômico) saiu do estoque: a movimentação fica com o código.
            ->and(StockMovement::where('item_code', 'OVO-1')->value('item_id'))->toBeNull()
            ->and(Item::find($eggItem->id))->toBeNull();
    });

    it('records nothing when the production fails', function () {
        $flour = Product::factory()->bulk()->create();
        $bread = Product::factory()->create();
        $bread->components()->attach($flour->id, ['quantity_required' => '5']);
        Item::factory()->create(['product_id' => $flour->id, 'quantity' => '1']);

        $this->postJson('/api/items', ['product_id' => $bread->id, 'consume_components' => true])->assertUnprocessable();

        expect(StockMovement::count())->toBe(0);
    });

    it('records quantity adjustments with the signed difference and transfers between storages', function () {
        $from = Storage::factory()->create(['name' => 'A']);
        $to = Storage::factory()->create(['name' => 'B']);
        $item = Item::factory()->create(['product_id' => Product::factory()->bulk(), 'code' => 'LOTE', 'quantity' => '10', 'storage_id' => $from->id]);

        $this->patchJson("/api/items/{$item->id}", ['quantity_subtract' => 2.5])->assertOk();
        $this->patchJson("/api/items/{$item->id}", ['quantity' => 20])->assertOk();
        $this->patchJson("/api/items/{$item->id}", ['storage_id' => $to->id])->assertOk();
        $this->patchJson("/api/items/{$item->id}", ['produced_at' => '2026-11-01'])->assertOk();

        expect(movementRows())->toBe([
            ['type' => 'adjustment', 'item_code' => 'LOTE', 'quantity' => '-2.5000', 'balance' => '7.5000'],
            ['type' => 'adjustment', 'item_code' => 'LOTE', 'quantity' => '12.5000', 'balance' => '20.0000'],
            ['type' => 'transfer', 'item_code' => 'LOTE', 'quantity' => '0.0000', 'balance' => '20.0000'],
        ])->and(StockMovement::where('type', 'transfer')->first()->only(['storage_id', 'storage_name', 'previous_storage_name']))->toBe([
            'storage_id' => $to->id,
            'storage_name' => 'B',
            'previous_storage_name' => 'A',
        ]);
    });

    it('does not record an adjustment that keeps the same quantity', function () {
        $item = Item::factory()->create(['product_id' => Product::factory()->bulk(), 'quantity' => '4']);

        $this->patchJson("/api/items/{$item->id}", ['quantity' => 4])->assertOk();

        expect(StockMovement::count())->toBe(0);
    });

    it('records a write-off when an item is deleted', function () {
        $item = Item::factory()->create(['product_id' => Product::factory()->bulk(), 'code' => 'LOTE', 'quantity' => '3']);

        $this->deleteJson("/api/items/{$item->id}")->assertOk();

        expect(movementRows())->toBe([
            ['type' => 'write_off', 'item_code' => 'LOTE', 'quantity' => '-3.0000', 'balance' => '0.0000'],
        ]);
    });

    it('records the write-off reason when one is given', function (?string $reason) {
        $item = Item::factory()->create(['code' => 'BOLO-0001']);

        $this->deleteJson("/api/items/{$item->id}", array_filter(['reason' => $reason]))->assertOk();

        expect(StockMovement::sole()->reason?->value)->toBe($reason);
    })->with(['expired', 'damaged', 'lost', 'other', null]);

    it('rejects an unknown write-off reason and keeps the item', function () {
        $item = Item::factory()->create();

        $this->deleteJson("/api/items/{$item->id}", ['reason' => 'stolen?'])
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Invalid write-off reason. Use: expired, damaged, lost or other.');

        expect(Item::find($item->id))->not->toBeNull()
            ->and(StockMovement::count())->toBe(0);
    });

    it('records the delivery of each line of the package, with the package id', function () {
        $sugar = Product::factory()->bulk()->create();
        $lot = Item::factory()->create(['product_id' => $sugar->id, 'code' => 'ACU', 'quantity' => '5']);
        $chair = Item::factory()->create(['code' => 'CAD-1']);
        $package = DeliveryPackage::factory()->create();

        foreach ([[$lot, '2'], [$chair, '1']] as [$item, $quantity]) {
            DeliveryPackageLine::create([
                'delivery_package_id' => $package->id,
                'product_id' => $item->product_id,
                'item_id' => $item->id,
                'item_code' => $item->code,
                'quantity' => $quantity,
            ]);
        }

        $this->postJson("/api/delivery-packages/{$package->id}/deliver")->assertOk();

        expect(movementRows())->toBe([
            ['type' => 'delivery', 'item_code' => 'ACU', 'quantity' => '-2.0000', 'balance' => '3.0000'],
            ['type' => 'delivery', 'item_code' => 'CAD-1', 'quantity' => '-1.0000', 'balance' => '0.0000'],
        ])->and(StockMovement::pluck('delivery_package_id')->unique()->all())->toBe([$package->id]);
    });

    it('does not record reservations in pending packages', function () {
        $item = Item::factory()->create();

        $this->postJson('/api/delivery-packages', ['items' => [['product_id' => $item->product_id, 'quantity' => 1]]])->assertCreated();

        expect(StockMovement::count())->toBe(0);
    });
});

describe('ledger reconciliation (runs after every feature test)', function () {
    it('detects stock changed without a movement', function () {
        $storage = Storage::factory()->create();
        $lot = $this->postJson('/api/items', ['product_id' => Product::factory()->bulk()->create()->id, 'quantity' => 10])
            ->assertCreated()
            ->json('data.items.0');

        expect(stockLedgerViolations())->toBe([]);

        // O que o ArchitectureTest não pega: uma escrita "por fora" das Actions.
        Item::query()->whereKey($lot['id'])->update(['quantity' => '7']);
        expect(stockLedgerViolations())->toHaveCount(1);

        Item::query()->whereKey($lot['id'])->update(['quantity' => '10', 'storage_id' => $storage->id]);
        expect(stockLedgerViolations())->toHaveCount(1);

        // Volta ao estado do ledger para o `afterEach` global passar.
        Item::query()->whereKey($lot['id'])->update(['storage_id' => null]);
        expect(stockLedgerViolations())->toBe([]);
    });
});

describe('GET /api/stock-movements', function () {
    it('lists the most recent first, with the exact shape', function () {
        $product = Product::factory()->create(['sku' => 'X']);
        $this->postJson('/api/items', ['product_id' => $product->id])->assertCreated();
        travelTo('2026-11-10 11:00:00');
        $this->deleteJson('/api/items/' . Item::first()->id)->assertOk();

        $response = listMovements()->assertOk()
            ->assertJsonPath('data.meta', ['page' => 1, 'per_page' => 8, 'total' => 2, 'last_page' => 1]);

        expect(array_keys($response->json('data.items.0')))->toBe([
            'id', 'type', 'item_id', 'item_code', 'product_id', 'product_name', 'unit_abbreviation', 'quantity', 'balance',
            'storage_id', 'storage_name', 'previous_storage_name', 'reason', 'delivery_package_id', 'user_id', 'user_name', 'created_at',
        ])
            ->and(collect($response->json('data.items'))->pluck('type')->all())->toBe(['write_off', 'entry'])
            ->and($response->json('data.items.0'))->toMatchArray(['item_id' => null, 'item_code' => 'X-0001', 'quantity' => '-1.0000']);
    });

    it('filters by search, type, product, storage and dates, and paginates', function () {
        $storage = Storage::factory()->create();
        $cake = Product::factory()->create(['name' => 'Bolo de cenoura', 'sku' => 'BOLO']);
        $chair = Product::factory()->create(['name' => 'Cadeira', 'sku' => 'CAD']);

        travelTo('2026-11-08 09:00:00');
        $this->postJson('/api/items', ['product_id' => $cake->id, 'quantity' => 3])->assertCreated();
        travelTo('2026-11-10 09:00:00');
        $this->postJson('/api/items', ['product_id' => $chair->id, 'storage_id' => $storage->id])->assertCreated();
        $this->deleteJson('/api/items/' . Item::where('code', 'BOLO-0001')->value('id'))->assertOk();

        $codes = fn(string $query) => collect(listMovements($query)->assertOk()->json('data.items'))
            ->map(fn(array $row) => "{$row['type']}:{$row['item_code']}")->all();

        expect($codes('?search=cenoura&type=entry'))->toBe(['entry:BOLO-0003', 'entry:BOLO-0002', 'entry:BOLO-0001'])
            ->and($codes('?search=cad-0'))->toBe(['entry:CAD-0001'])
            ->and($codes('?type=write_off'))->toBe(['write_off:BOLO-0001'])
            ->and($codes("?product_id={$chair->id}"))->toBe(['entry:CAD-0001'])
            ->and($codes("?storage_id={$storage->id}"))->toBe(['entry:CAD-0001'])
            ->and($codes('?date_from=2026-11-08&date_to=2026-11-08'))->toBe(['entry:BOLO-0003', 'entry:BOLO-0002', 'entry:BOLO-0001'])
            ->and($codes('?date_from=2026-11-09'))->toBe(['write_off:BOLO-0001', 'entry:CAD-0001'])
            ->and($codes('?type=nope&date_from=ontem'))->toHaveCount(5);

        listMovements('?per_page=2&page=3')
            ->assertJsonPath('data.meta', ['page' => 3, 'per_page' => 2, 'total' => 5, 'last_page' => 3])
            ->assertJsonCount(1, 'data.items');
    });

    it('answers how much was lost to expiration in a month', function () {
        $expired = Item::factory()->create(['code' => 'A']);
        $damaged = Item::factory()->create(['code' => 'B']);
        $this->deleteJson("/api/items/{$expired->id}", ['reason' => 'expired'])->assertOk();
        $this->deleteJson("/api/items/{$damaged->id}", ['reason' => 'damaged'])->assertOk();

        expect(collect(listMovements('?type=write_off&reason=expired&date_from=2026-11-01&date_to=2026-11-30')->json('data.items'))->pluck('item_code')->all())
            ->toBe(['A']);
    });

    it('filters dates by the calendar day of the business timezone', function () {
        config(['app.business_timezone' => 'America/Sao_Paulo']);
        $product = Product::factory()->create(['sku' => 'NOITE']);

        // 22h de 10/11 em São Paulo (01h de 11/11 em UTC): para a loja, foi no dia 10.
        travelTo('2026-11-11 01:00:00');
        $this->postJson('/api/items', ['product_id' => $product->id])->assertCreated()->assertJsonPath('data.items.0.produced_at', '2026-11-10');

        expect(listMovements('?date_from=2026-11-10&date_to=2026-11-10')->json('data.meta.total'))->toBe(1)
            ->and(listMovements('?date_from=2026-11-11')->json('data.meta.total'))->toBe(0);
    });

    it('requires a session', function () {
        $this->withToken('invalid');

        listMovements()->assertUnauthorized();
    });

    it('returns 500 when the database fails', function () {
        failTable('stock_movements');

        listMovements()->assertStatus(500)->assertJsonPath('message.pt-br', 'Erro ao buscar as movimentações.');
    });
});

describe('GET /api/stock-movements/summary', function () {
    it('sums the movements per product, type and reason, with the exact shape', function () {
        $kg = Unit::factory()->create(['abbreviation' => 'kg']);
        $flour = Product::factory()->bulk()->create(['name' => 'Farinha', 'sku' => 'FAR', 'unit_id' => $kg->id]);
        $cake = Product::factory()->create(['name' => 'Bolo', 'sku' => 'BOLO']);

        $this->postJson('/api/items', ['product_id' => $flour->id, 'quantity' => 10])->assertCreated();
        $this->postJson('/api/items', ['product_id' => $flour->id, 'quantity' => 2.5])->assertCreated();
        $this->postJson('/api/items', ['product_id' => $cake->id, 'quantity' => 3])->assertCreated();
        $this->deleteJson('/api/items/' . Item::where('code', 'FAR-0001')->value('id'), ['reason' => 'expired'])->assertOk();
        $this->deleteJson('/api/items/' . Item::where('code', 'BOLO-0001')->value('id'), ['reason' => 'expired'])->assertOk();
        $this->deleteJson('/api/items/' . Item::where('code', 'BOLO-0002')->value('id'))->assertOk();

        $response = $this->getJson('/api/stock-movements/summary')->assertOk();

        expect($response->json('data.items'))->toBe([
            ['product_id' => $cake->id, 'product_name' => 'Bolo', 'unit_abbreviation' => $cake->unit->abbreviation, 'type' => 'entry', 'reason' => null, 'quantity' => '3.0000', 'movements_count' => 3],
            // Baixa sem motivo fica visível como `reason: null`: o quanto da perda ainda não foi classificado.
            ['product_id' => $cake->id, 'product_name' => 'Bolo', 'unit_abbreviation' => $cake->unit->abbreviation, 'type' => 'write_off', 'reason' => 'expired', 'quantity' => '-1.0000', 'movements_count' => 1],
            ['product_id' => $cake->id, 'product_name' => 'Bolo', 'unit_abbreviation' => $cake->unit->abbreviation, 'type' => 'write_off', 'reason' => null, 'quantity' => '-1.0000', 'movements_count' => 1],
            ['product_id' => $flour->id, 'product_name' => 'Farinha', 'unit_abbreviation' => 'kg', 'type' => 'entry', 'reason' => null, 'quantity' => '12.5000', 'movements_count' => 2],
            ['product_id' => $flour->id, 'product_name' => 'Farinha', 'unit_abbreviation' => 'kg', 'type' => 'write_off', 'reason' => 'expired', 'quantity' => '-10.0000', 'movements_count' => 1],
        ]);
    });

    it('answers how many kg were lost to expiration in a month with one row per product', function () {
        $kg = Unit::factory()->create(['abbreviation' => 'kg']);
        $flour = Product::factory()->bulk()->create(['name' => 'Farinha', 'sku' => 'FAR', 'unit_id' => $kg->id]);

        travelTo('2026-10-31 12:00:00');
        $this->postJson('/api/items', ['product_id' => $flour->id, 'quantity' => 4])->assertCreated();
        $this->deleteJson('/api/items/' . Item::where('code', 'FAR-0001')->value('id'), ['reason' => 'expired'])->assertOk();

        travelTo('2026-11-10 12:00:00');
        foreach ([1.25, 2] as $quantity) {
            $this->postJson('/api/items', ['product_id' => $flour->id, 'quantity' => $quantity])->assertCreated();
        }
        foreach (Item::where('quantity', '>', 0)->pluck('id') as $id) {
            $this->deleteJson("/api/items/{$id}", ['reason' => 'expired'])->assertOk();
        }

        $this->getJson('/api/stock-movements/summary?type=write_off&reason=expired&date_from=2026-11-01&date_to=2026-11-30')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', '-3.2500')
            ->assertJsonPath('data.items.0.unit_abbreviation', 'kg')
            ->assertJsonPath('data.items.0.movements_count', 2);
    });

    it('keeps the copied name of a deleted product', function () {
        $product = Product::factory()->bulk()->create(['name' => 'Fermento', 'sku' => 'FER']);
        $this->postJson('/api/items', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
        $this->patchJson('/api/items/' . Item::first()->id, ['quantity' => 0])->assertOk();
        $this->deleteJson("/api/products/{$product->id}")->assertOk();

        $this->getJson('/api/stock-movements/summary?type=entry')
            ->assertOk()
            ->assertJsonPath('data.items.0.product_id', null)
            ->assertJsonPath('data.items.0.product_name', 'Fermento');
    });

    it('returns an empty list when nothing matches', function () {
        $this->getJson('/api/stock-movements/summary?date_from=2030-01-01')
            ->assertOk()
            ->assertJsonPath('data.items', []);
    });

    it('requires a session', function () {
        $this->withToken('invalid');

        $this->getJson('/api/stock-movements/summary')->assertUnauthorized();
    });

    it('returns 500 when the database fails', function () {
        failTable('stock_movements');

        $this->getJson('/api/stock-movements/summary')
            ->assertStatus(500)
            ->assertJsonPath('message.pt-br', 'Erro ao resumir as movimentações.');
    });
});
