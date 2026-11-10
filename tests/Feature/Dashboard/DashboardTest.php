<?php

use App\Base\Cache\DashboardCache;
use App\Models\Inventory\Item;
use App\Models\Inventory\Product;
use App\Models\Inventory\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Ana']);
    dashboardAt('2026-11-10 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Muda o relógio e emite um token novo (o access token vale 15 min).
 */
function dashboardAt(string $now): void {
    Carbon::setTestNow($now);
    test()->withToken(accessToken(test()->user));
}

function getDashboard(string $query = ''): TestResponse {
    return test()->getJson("/api/dashboard{$query}");
}

/**
 * Confeitaria em dez dias: bolos (atômicos, validade 3 dias), farinha (a granel) e velas (sem saída).
 * - 01/11: 2 bolos (vencem 04/11);
 * - 05/11: baixa de um bolo vencido (o outro continua no estoque, vencido);
 * - 09/11: 3 bolos (vencem 12/11), 10 kg de farinha e 1 vela;
 * - 10/11: pacote entregue (2 bolos + 4 kg) e pacote pendente (1 bolo).
 *
 * @return array{cake: Product, flour: Product, candle: Product}
 */
function bakeryMonth(): array {
    $un = Unit::factory()->create(['abbreviation' => 'un']);
    $kg = Unit::factory()->create(['abbreviation' => 'kg']);
    $cake = Product::factory()->create(['name' => 'Bolo', 'sku' => 'BOL', 'unit_id' => $un->id, 'shelf_life_days' => 3]);
    $flour = Product::factory()->bulk()->create(['name' => 'Farinha', 'sku' => 'FAR', 'unit_id' => $kg->id, 'shelf_life_days' => 30]);
    $candle = Product::factory()->create(['name' => 'Vela', 'sku' => 'VEL', 'unit_id' => $un->id]);

    dashboardAt('2026-11-01 09:00:00');
    test()->postJson('/api/items', ['product_id' => $cake->id, 'quantity' => 2])->assertCreated();

    dashboardAt('2026-11-05 09:00:00');
    test()->deleteJson('/api/items/' . Item::query()->where('code', 'BOL-0001')->value('id'), ['reason' => 'expired'])->assertOk();

    dashboardAt('2026-11-09 09:00:00');
    test()->postJson('/api/items', ['product_id' => $cake->id, 'quantity' => 3])->assertCreated();
    test()->postJson('/api/items', ['product_id' => $flour->id, 'quantity' => 10])->assertCreated();
    test()->postJson('/api/items', ['product_id' => $candle->id, 'quantity' => 1])->assertCreated();

    dashboardAt('2026-11-10 09:00:00');
    $delivered = test()->postJson('/api/delivery-packages', ['items' => [
        ['product_id' => $cake->id, 'quantity' => 2],
        ['product_id' => $flour->id, 'quantity' => 4],
    ]])->assertCreated()->json('data.id');
    test()->postJson("/api/delivery-packages/{$delivered}/deliver")->assertOk();
    test()->postJson('/api/delivery-packages', ['items' => [['product_id' => $cake->id, 'quantity' => 1]]])->assertCreated();

    dashboardAt('2026-11-10 10:00:00');

    return compact('cake', 'flour', 'candle');
}

describe('GET /api/dashboard', function () {
    it('summarizes the stock, deliveries, losses and activity of the period', function () {
        ['cake' => $cake, 'flour' => $flour] = bakeryMonth();

        $response = getDashboard()->assertOk()->assertJsonPath('success', true);
        $data = $response->json('data');

        expect(array_keys($data))->toBe(['generated_at', 'period', 'stock', 'deliveries', 'losses', 'activity', 'top_delivered', 'restock', 'expiration_alerts'])
            ->and($data['generated_at'])->toBe('2026-11-10T10:00:00.000000Z')
            ->and($data['period'])->toBe(['days' => 30, 'from' => '2026-10-12', 'to' => '2026-11-10'])
            ->and($data['stock'])->toBe([
                'products_count' => 3,
                'products_in_stock' => 3,
                'out_of_stock_count' => 1,
                'items_count' => 4,
                'expired_items_count' => 1,
                'expiring_items_count' => 1,
            ])
            ->and($data['deliveries'])->toBe([
                'pending_count' => 1,
                'pending_with_expired_items_count' => 0,
                'oldest_pending_at' => '2026-11-10T09:00:00.000000Z',
                'delivered_count' => 1,
                'delivered_previous_count' => 0,
            ])
            ->and($data['losses'])->toBe([
                'movements_count' => 1,
                'previous_movements_count' => 0,
                'by_reason' => [['reason' => 'expired', 'movements_count' => 1]],
                'top_products' => [[
                    'product_id' => $cake->id,
                    'product_name' => 'Bolo',
                    'unit_abbreviation' => 'un',
                    'quantity' => '1.0000',
                    'movements_count' => 1,
                    'expired_count' => 1,
                ]],
            ])
            ->and($data['top_delivered'])->toBe([
                ['product_id' => $flour->id, 'product_name' => 'Farinha', 'unit_abbreviation' => 'kg', 'quantity' => '4.0000', 'packages_count' => 1],
                ['product_id' => $cake->id, 'product_name' => 'Bolo', 'unit_abbreviation' => 'un', 'quantity' => '2.0000', 'packages_count' => 1],
            ])
            ->and($data['restock'])->toBe([
                [
                    'product_id' => $cake->id,
                    'product_name' => 'Bolo',
                    'unit_abbreviation' => 'un',
                    'free_quantity' => '0.0000',
                    'outflow_quantity' => '2.0000',
                    'daily_outflow' => '0.0666',
                    'coverage_days' => '0.0000',
                ],
                [
                    'product_id' => $flour->id,
                    'product_name' => 'Farinha',
                    'unit_abbreviation' => 'kg',
                    'free_quantity' => '6.0000',
                    'outflow_quantity' => '4.0000',
                    'daily_outflow' => '0.1333',
                    'coverage_days' => '45.0000',
                ],
            ])
            ->and($data['expiration_alerts'])->toBe([[
                'product_id' => $cake->id,
                'product_name' => 'Bolo',
                'unit_abbreviation' => 'un',
                'expired_quantity' => '1.0000',
                'expiring_quantity' => '1.0000',
                'next_expires_at' => '2026-11-12',
            ]]);

        $activity = collect($data['activity'])->keyBy('date');

        expect($data['activity'])->toHaveCount(30)
            ->and($data['activity'][0]['date'])->toBe('2026-10-12')
            ->and($data['activity'][29]['date'])->toBe('2026-11-10')
            ->and($activity['2026-10-12'])->toBe(['date' => '2026-10-12', 'inflow' => 0, 'outflow' => 0, 'losses' => 0])
            ->and($activity['2026-11-01'])->toBe(['date' => '2026-11-01', 'inflow' => 2, 'outflow' => 0, 'losses' => 0])
            ->and($activity['2026-11-05'])->toBe(['date' => '2026-11-05', 'inflow' => 0, 'outflow' => 0, 'losses' => 1])
            ->and($activity['2026-11-09'])->toBe(['date' => '2026-11-09', 'inflow' => 5, 'outflow' => 0, 'losses' => 0])
            ->and($activity['2026-11-10'])->toBe(['date' => '2026-11-10', 'inflow' => 0, 'outflow' => 3, 'losses' => 0]);
    });

    it('answers an empty system with zeros and empty lists', function () {
        $data = getDashboard()->assertOk()->json('data');

        expect($data['stock'])->toBe([
            'products_count' => 0,
            'products_in_stock' => 0,
            'out_of_stock_count' => 0,
            'items_count' => 0,
            'expired_items_count' => 0,
            'expiring_items_count' => 0,
        ])
            ->and($data['deliveries']['oldest_pending_at'])->toBeNull()
            ->and($data['losses']['by_reason'])->toBe([])
            ->and($data['top_delivered'])->toBe([])
            ->and($data['restock'])->toBe([])
            ->and($data['expiration_alerts'])->toBe([]);
    });

    it('counts the previous period of the same size for the trend', function () {
        bakeryMonth();

        $data = getDashboard('?period=7')->assertOk()->json('data');

        expect($data['period'])->toBe(['days' => 7, 'from' => '2026-11-04', 'to' => '2026-11-10'])
            ->and($data['activity'])->toHaveCount(7)
            ->and($data['losses']['movements_count'])->toBe(1)
            ->and($data['restock'][0]['daily_outflow'])->toBe('0.2857');

        // 06/11–12/11: a baixa de 05/11 passa para o período anterior (30/10–05/11).
        dashboardAt('2026-11-12 10:00:00');

        expect(getDashboard('?period=7')->json('data.losses'))
            ->toMatchArray(['movements_count' => 0, 'previous_movements_count' => 1]);
    });

    it('flags pending packages holding an item that expired after the reservation', function () {
        bakeryMonth();

        // O bolo reservado vence em 12/11.
        dashboardAt('2026-11-13 10:00:00');

        expect(getDashboard()->json('data.deliveries.pending_with_expired_items_count'))->toBe(1);
    });

    it('falls back to 30 days for an unknown period', function (string $period) {
        expect(getDashboard("?period={$period}")->assertOk()->json('data.period.days'))->toBe(30);
    })->with(['15', 'abc', '-7', '7.5', '']);

    it('buckets the activity by the business day, not the UTC day', function () {
        config(['app.business_timezone' => 'America/Sao_Paulo']);
        $product = Product::factory()->create(['sku' => 'ARM']);

        // 01:00 UTC de 10/11 = 22:00 de 09/11 em São Paulo.
        dashboardAt('2026-11-10 01:00:00');
        $this->postJson('/api/items', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();

        $activity = collect(getDashboard()->json('data.activity'))->keyBy('date');

        expect($activity['2026-11-09']['inflow'])->toBe(1)
            ->and($activity->has('2026-11-10'))->toBeFalse();
    });

    it('requires a session', function () {
        $this->withToken('invalid')->getJson('/api/dashboard')->assertUnauthorized();
    });

    it('answers 500 with the dashboard message when the database fails', function () {
        failTable('stock_movements');

        getDashboard()->assertStatus(500)
            ->assertJsonPath('message.en-us', 'Error building the dashboard.')
            ->assertJsonPath('data', null);
    });
});

describe('cache', function () {
    it('serves the stored dashboard until a stock, package or product write changes the version', function () {
        $unit = Unit::factory()->create();
        $product = Product::factory()->create(['sku' => 'ARM', 'unit_id' => $unit->id]);

        expect(getDashboard()->json('data.stock.products_count'))->toBe(1);

        // Escrita por fora das Actions: não troca a versão, então o painel guardado continua valendo.
        Product::factory()->create(['unit_id' => $unit->id]);
        expect(getDashboard()->json('data.stock.products_count'))->toBe(1);

        // Mudança de estoque pela API: versão nova, painel recalculado.
        $this->postJson('/api/items', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
        expect(getDashboard()->json('data.stock'))->toMatchArray(['products_count' => 2, 'items_count' => 1]);

        // Cadastro de produto pela API também troca a versão.
        $this->postJson('/api/products', ['name' => 'Mesa', 'unit_id' => $unit->id])->assertCreated();
        expect(getDashboard()->json('data.stock.products_count'))->toBe(3);
    });

    it('keeps one entry per period', function () {
        Product::factory()->create();

        getDashboard('?period=7');
        Product::factory()->create();

        expect(getDashboard('?period=7')->json('data.stock.products_count'))->toBe(1)
            ->and(getDashboard('?period=90')->json('data.stock.products_count'))->toBe(2);
    });

    it('rebuilds after the maximum age even without writes', function () {
        Product::factory()->create();
        getDashboard();
        Product::factory()->create();

        dashboardAt('2026-11-10 10:04:59');
        expect(getDashboard()->json('data.stock.products_count'))->toBe(1);

        dashboardAt('2026-11-10 10:05:00');
        expect(getDashboard()->json('data.stock.products_count'))->toBe(2);
    });

    it('rebuilds when the business day changes ("expired" depends on the day)', function () {
        config(['dashboard.max_age_seconds' => 86400]);
        $product = Product::factory()->create(['sku' => 'BOL', 'shelf_life_days' => 1]);
        $this->postJson('/api/items', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();

        dashboardAt('2026-11-11 23:59:00');
        expect(getDashboard()->json('data.stock.expired_items_count'))->toBe(0);

        dashboardAt('2026-11-12 00:01:00');
        expect(getDashboard()->json('data.stock.expired_items_count'))->toBe(1);
    });

    it('does not change the version when the write is rolled back', function () {
        $product = Product::factory()->create();
        getDashboard();
        $version = Cache::get(DashboardCache::VERSION_KEY);

        // Sem estoque: a Action chama invalidate() dentro da transação e depois falha (422).
        $this->postJson('/api/delivery-packages', ['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertUnprocessable();

        expect(Cache::get(DashboardCache::VERSION_KEY))->toBe($version);
    });

    it('serves the previous entry at once while another request is rebuilding', function () {
        Product::factory()->create();
        getDashboard();
        Product::factory()->create();
        DashboardCache::invalidate();

        $lock = Cache::lock('dashboard:30:build', 10);
        $lock->get();

        expect(getDashboard()->assertOk()->json('data.stock.products_count'))->toBe(1);

        $lock->release();

        expect(getDashboard()->json('data.stock.products_count'))->toBe(2);
    });

    it('computes on its own instead of failing when nothing is stored and the rebuild does not finish in time', function () {
        config(['dashboard.build_wait_seconds' => 1]);
        Product::factory()->create();

        $lock = Cache::lock('dashboard:30:build', 10);
        $lock->get();

        expect(getDashboard()->assertOk()->json('data.stock.products_count'))->toBe(1);

        $lock->release();
    });

    it('never serves an entry from the previous business day, even while another request is rebuilding', function () {
        config(['dashboard.build_wait_seconds' => 1]);
        $product = Product::factory()->create(['sku' => 'BOL', 'shelf_life_days' => 1]);
        $this->postJson('/api/items', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
        getDashboard();

        $lock = Cache::lock('dashboard:30:build', 10);
        $lock->get();

        dashboardAt('2026-11-12 10:00:00');
        expect(getDashboard()->json('data.stock.expired_items_count'))->toBe(1);

        $lock->release();
    });
});
