<?php

use Illuminate\Support\Carbon;

/**
 * Cenário: confeitaria. Cada bolo é atômico (código próprio) e vence 5 dias após a produção;
 * farinha, ovos e chocolate são a granel, em lotes com vencimentos diferentes.
 * Produção em lote (8 bolos de uma vez), venda FEFO e baixa dos vencidos.
 */

beforeEach(function () {
    $this->withToken(accessToken());
    Carbon::setTestNow('2026-11-09 08:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function bakeryPost(string $url, array $payload): array {
    return test()->postJson($url, $payload)->assertCreated()->json('data');
}

it('bakes cakes in batches, sells the oldest first and lists the expired ones', function () {
    $kg = bakeryPost('/api/units', ['name' => 'Quilograma', 'abbreviation' => 'kg'])['id'];
    $un = bakeryPost('/api/units', ['name' => 'Unidade', 'abbreviation' => 'un'])['id'];
    $showcase = bakeryPost('/api/storages', ['name' => 'Vitrine'])['id'];

    $flour = bakeryPost('/api/products', ['name' => 'Farinha de trigo', 'sku' => 'FAR', 'unit_id' => $kg, 'is_atomic' => false, 'shelf_life_days' => 180]);
    $eggs = bakeryPost('/api/products', ['name' => 'Ovos', 'sku' => 'OVO', 'unit_id' => $un, 'is_atomic' => false, 'shelf_life_days' => 21]);
    $chocolate = bakeryPost('/api/products', ['name' => 'Chocolate', 'sku' => 'CHO', 'unit_id' => $kg, 'is_atomic' => false]);
    $cake = bakeryPost('/api/products', [
        'name' => 'Bolo de chocolate',
        'sku' => 'BOLO-CH',
        'unit_id' => $un,
        'shelf_life_days' => 5,
        'components' => [
            ['id' => $flour['id'], 'quantity_required' => 0.3],
            ['id' => $eggs['id'], 'quantity_required' => 3],
            ['id' => $chocolate['id'], 'quantity_required' => 0.2],
        ],
    ]);

    // Dois lotes de farinha: o mais antigo (produzido em maio) vence antes e sai primeiro.
    $oldFlour = bakeryPost('/api/items', ['product_id' => $flour['id'], 'quantity' => 2, 'produced_at' => '2026-05-20'])['items'][0];
    $newFlour = bakeryPost('/api/items', ['product_id' => $flour['id'], 'quantity' => 25])['items'][0];
    bakeryPost('/api/items', ['product_id' => $eggs['id'], 'quantity' => 60]);
    bakeryPost('/api/items', ['product_id' => $chocolate['id'], 'quantity' => 5]);

    expect($oldFlour['expires_at'])->toBe('2026-11-16')
        ->and($newFlour['expires_at'])->toBe('2027-05-08');

    // Ontem: 4 bolos. Hoje: 8 bolos de uma vez, sem formulário item a item.
    $yesterday = bakeryPost('/api/items', ['product_id' => $cake['id'], 'quantity' => 4, 'produced_at' => '2026-11-08', 'storage_id' => $showcase, 'consume_components' => true])['items'];
    $response = $this->postJson('/api/items', ['product_id' => $cake['id'], 'quantity' => 8, 'storage_id' => $showcase, 'consume_components' => true])
        ->assertCreated()
        ->assertJsonPath('message.pt-br', '8 item(ns) gerado(s) com sucesso.');
    $today = $response->json('data.items');

    expect(collect($yesterday)->pluck('expires_at')->unique()->all())->toBe(['2026-11-13'])
        ->and(collect($today)->pluck('expires_at')->unique()->all())->toBe(['2026-11-14'])
        ->and(collect($today)->pluck('code')->unique())->toHaveCount(8)
        ->and($today[0]['code'])->toBe('BOLO-CH-0005');

    // 12 bolos = 3,6 kg de farinha: os 2 kg do lote antigo (ainda na validade) acabam e 1,6 kg sai do novo.
    $stock = collect($this->getJson('/api/stock')->json('data.items'))->keyBy(fn($row) => $row['item']['code'] ?? $row['product']['name']);

    expect($stock['Bolo de chocolate'])->toMatchArray(['kind' => 'group', 'quantity' => '12.0000', 'next_expires_at' => '2026-11-13', 'expired_quantity' => '0.0000'])
        ->and($stock->has($oldFlour['code']))->toBeFalse() // lote zerado sai da operação
        ->and(collect($this->getJson("/api/items?search={$oldFlour['code']}")->json('data.items'))->pluck('quantity')->all())->toBe(['0.0000'])
        ->and($stock[$newFlour['code']]['quantity'])->toBe('23.4000')
        ->and($stock['OVO-0001']['quantity'])->toBe('24.0000')
        ->and($stock['CHO-0001']['quantity'])->toBe('2.6000');

    // Venda de 5 bolos: saem os 4 de ontem e 1 de hoje.
    $package = bakeryPost('/api/delivery-packages', ['items' => [['product_id' => $cake['id'], 'quantity' => 5]], 'notes' => 'Festa da Júlia']);

    expect(collect($package['items'][0]['allocations'])->pluck('code')->all())
        ->toBe(['BOLO-CH-0001', 'BOLO-CH-0002', 'BOLO-CH-0003', 'BOLO-CH-0004', 'BOLO-CH-0005']);

    $this->postJson("/api/delivery-packages/{$package['id']}/deliver")->assertOk();

    // Seis dias depois os 7 bolos que sobraram estão vencidos.
    Carbon::setTestNow('2026-11-15 08:00:00');
    $this->withToken(accessToken()); // o access token de 6 dias atrás já expirou

    $expired = $this->getJson('/api/items?expiration=expired')->json('data.items');
    $cakeRow = collect($this->getJson('/api/stock?search=bolo')->json('data.items'))->first();

    expect(collect($expired)->pluck('code')->all())->toBe(['BOLO-CH-0006', 'BOLO-CH-0007', 'BOLO-CH-0008', 'BOLO-CH-0009', 'BOLO-CH-0010', 'BOLO-CH-0011', 'BOLO-CH-0012'])
        ->and($cakeRow)->toMatchArray(['quantity' => '7.0000', 'expired_quantity' => '7.0000']);

    // Baixa dos vencidos.
    foreach ($expired as $item) {
        $this->deleteJson("/api/items/{$item['id']}")->assertOk();
    }

    expect(collect($this->getJson('/api/stock?search=bolo')->json('data.items')))->toBeEmpty();
});
