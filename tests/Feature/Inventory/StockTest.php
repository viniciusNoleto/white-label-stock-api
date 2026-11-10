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

function listStock(string $query = ''): TestResponse {
    return test()->getJson("/api/stock{$query}");
}

describe('GET /api/stock', function () {
    it('returns an empty first page', function () {
        listStock()
            ->assertOk()
            ->assertJsonPath('data', ['items' => [], 'meta' => ['page' => 1, 'per_page' => 8, 'total' => 0, 'last_page' => 1]]);
    });

    it('groups atomic items in one row per product and shows each bulk lot as it is', function () {
        $unit = Unit::factory()->create(['name' => 'Unidade', 'abbreviation' => 'un']);
        $showroom = Storage::factory()->create(['name' => 'Showroom']);
        $depot = Storage::factory()->create(['name' => 'Depósito']);
        $cabinet = Product::factory()->create(['name' => 'Armário A', 'sku' => 'ARM-A', 'unit_id' => $unit->id]);
        $screw = Product::factory()->bulk()->create(['name' => 'Parafuso', 'unit_id' => $unit->id]);
        Product::factory()->create(['name' => 'Sem estoque']);

        $first = Item::factory()->create(['product_id' => $cabinet->id, 'storage_id' => $showroom->id, 'expires_at' => '2026-11-01']);
        Item::factory()->create(['product_id' => $cabinet->id, 'storage_id' => $depot->id, 'expires_at' => '2026-11-20']);
        Item::factory()->create(['product_id' => $cabinet->id, 'storage_id' => $depot->id]);
        Item::factory()->create(['product_id' => $cabinet->id]);
        $lot = Item::factory()->create(['product_id' => $screw->id, 'code' => 'PAR-0001', 'quantity' => '500', 'storage_id' => $depot->id]);
        Item::factory()->create(['product_id' => $screw->id, 'code' => 'PAR-0002', 'quantity' => '120.5', 'expires_at' => '2026-10-01']);
        $package = DeliveryPackage::factory()->create();
        DeliveryPackageLine::create(['delivery_package_id' => $package->id, 'product_id' => $cabinet->id, 'item_id' => $first->id, 'item_code' => $first->code, 'quantity' => '1']);
        DeliveryPackageLine::create(['delivery_package_id' => $package->id, 'product_id' => $screw->id, 'item_id' => $lot->id, 'item_code' => $lot->code, 'quantity' => '200']);

        $response = listStock()->assertOk();
        [$group, $expired, $record] = $response->json('data.items');

        expect($response->json('data.meta.total'))->toBe(3)
            ->and(array_keys($group))->toBe(['key', 'kind', 'product', 'item', 'quantity', 'reserved_quantity', 'items_count', 'next_expires_at', 'expired_quantity', 'storages'])
            ->and($group)->toBe([
                'key' => "product-{$cabinet->id}",
                'kind' => 'group',
                'product' => ['id' => $cabinet->id, 'name' => 'Armário A', 'sku' => 'ARM-A', 'is_atomic' => true, 'unit' => ['id' => $unit->id, 'name' => 'Unidade', 'abbreviation' => 'un']],
                'item' => null,
                'quantity' => '4.0000',
                'reserved_quantity' => '1.0000',
                'items_count' => 4,
                'next_expires_at' => '2026-11-01',
                'expired_quantity' => '1.0000',
                'storages' => [['id' => $depot->id, 'name' => 'Depósito'], ['id' => $showroom->id, 'name' => 'Showroom']],
            ])
            ->and($record)->toMatchArray([
                'key' => "item-{$lot->id}",
                'kind' => 'record',
                'item' => ['id' => $lot->id, 'code' => 'PAR-0001'],
                'quantity' => '500.0000',
                'reserved_quantity' => '200.0000',
                'items_count' => 1,
                'next_expires_at' => null,
                'expired_quantity' => '0.0000',
                'storages' => [['id' => $depot->id, 'name' => 'Depósito']],
            ])
            ->and($expired)->toMatchArray([
                'item' => ['id' => Item::where('code', 'PAR-0002')->value('id'), 'code' => 'PAR-0002'],
                'quantity' => '120.5000',
                'expired_quantity' => '120.5000',
                'storages' => [],
            ]);
    });

    it('leaves empty bulk lots out of the operation view', function () {
        $flour = Product::factory()->bulk()->create(['name' => 'Farinha']);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'EMPTY', 'quantity' => '0']);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'FULL', 'quantity' => '3']);

        expect(collect(listStock()->json('data.items'))->pluck('item.code')->all())->toBe(['FULL']);
    });

    it('searches by product name or SKU and paginates', function () {
        $cake = Product::factory()->create(['name' => 'Bolo', 'sku' => 'CHOC']);
        $flour = Product::factory()->bulk()->create(['name' => 'Farinha', 'sku' => 'FAR']);
        Item::factory()->count(3)->sequence(fn($sequence) => ['code' => 'BOLO-' . $sequence->index])->create(['product_id' => $cake->id]);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'FAR-LOTE-7']);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'FAR-LOTE-8']);

        $keys = fn(string $query) => collect(listStock($query)->json('data.items'))->pluck('key')->all();

        expect($keys('?search=choc'))->toBe(["product-{$cake->id}"])
            ->and($keys('?search=bol'))->toBe(["product-{$cake->id}"])
            ->and(count($keys('?search=farinha')))->toBe(2)
            ->and(count($keys('?search=far')))->toBe(2)
            ->and($keys('?search=bolo-'))->toBe([])
            ->and($keys('?search=lote-8'))->toBe([])
            ->and(listStock('?page=2&per_page=2')->json('data.meta'))->toBe(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
    });

    it('filters by storage and exact expiration date before grouping', function () {
        $shelf = Storage::factory()->create(['name' => 'Prateleira']);
        $cake = Product::factory()->create(['name' => 'Bolo']);
        $flour = Product::factory()->bulk()->create(['name' => 'Farinha']);
        Item::factory()->create(['product_id' => $cake->id, 'storage_id' => $shelf->id, 'expires_at' => '2026-11-12']);
        Item::factory()->create(['product_id' => $cake->id, 'storage_id' => $shelf->id, 'expires_at' => '2026-11-20']);
        Item::factory()->create(['product_id' => $cake->id, 'expires_at' => '2026-11-12']);
        Item::factory()->create(['product_id' => $flour->id, 'code' => 'FAR-1', 'quantity' => '2', 'expires_at' => '2026-11-12']);

        $rows = fn(string $query) => collect(listStock($query)->json('data.items'))->map(fn($row) => [$row['product']['name'], $row['quantity']])->all();

        expect($rows("?storage_id={$shelf->id}"))->toBe([['Bolo', '2.0000']])
            ->and($rows('?expires_at=2026-11-12'))->toBe([['Bolo', '2.0000'], ['Farinha', '2.0000']])
            ->and($rows("?storage_id={$shelf->id}&expires_at=2026-11-12"))->toBe([['Bolo', '1.0000']])
            ->and(count($rows('?expires_at=nope&storage_id=x')))->toBe(2);
    });

    it('returns 500 when the database fails', function () {
        failTable('items');

        listStock()
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error fetching stock.');
    });
});
