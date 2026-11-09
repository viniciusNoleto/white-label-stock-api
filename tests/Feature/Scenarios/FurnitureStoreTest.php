<?php

/**
 * Cenário: loja de móveis. O armário modelo A leva 100 parafusos, 4 chapas de MDF brancas e
 * 2 chapas de MDF castanho. Chapas e armários são atômicos (cada um com código); parafusos são a granel.
 * Tudo pela API, como o web faria.
 */

beforeEach(function () {
    $this->withToken(accessToken());
});

function furnitureProduct(array $payload): array {
    return test()->postJson('/api/products', $payload)->assertCreated()->json('data');
}

function furnitureItems(array $payload): array {
    return test()->postJson('/api/items', $payload)->assertCreated()->json('data.items');
}

/**
 * @return array<string, string> quantidade por linha da tela de operação (código do lote ou nome do produto)
 */
function furnitureStock(): array {
    return collect(test()->getJson('/api/stock')->assertOk()->json('data.items'))
        ->mapWithKeys(fn($row) => [$row['item']['code'] ?? $row['product']['name'] => $row['quantity']])
        ->all();
}

it('produces cabinets from the recipe, groups them in the operation view and finds each one by code', function () {
    $unit = $this->postJson('/api/units', ['name' => 'Unidade', 'abbreviation' => 'un'])->json('data.id');
    $depot = $this->postJson('/api/storages', ['name' => 'Depósito'])->json('data.id');
    $showroom = $this->postJson('/api/storages', ['name' => 'Showroom'])->json('data.id');

    $screw = furnitureProduct(['name' => 'Parafuso 4x40', 'sku' => 'PAR', 'unit_id' => $unit, 'is_atomic' => false]);
    $white = furnitureProduct(['name' => 'Chapa MDF branca', 'sku' => 'MDF-BR', 'unit_id' => $unit]);
    $brown = furnitureProduct(['name' => 'Chapa MDF castanho', 'sku' => 'MDF-CA', 'unit_id' => $unit]);
    $cabinet = furnitureProduct([
        'name' => 'Armário modelo A',
        'sku' => 'ARM-A',
        'unit_id' => $unit,
        'components' => [
            ['id' => $screw['id'], 'quantity_required' => 100],
            ['id' => $white['id'], 'quantity_required' => 4],
            ['id' => $brown['id'], 'quantity_required' => 2],
        ],
    ]);

    // Entrada de matéria-prima: um lote de parafusos e as chapas em lote (cada chapa com código).
    furnitureItems(['product_id' => $screw['id'], 'quantity' => 1000, 'storage_id' => $depot]);
    $whiteBoards = furnitureItems(['product_id' => $white['id'], 'quantity' => 20, 'storage_id' => $depot]);
    furnitureItems(['product_id' => $brown['id'], 'quantity' => 10, 'storage_id' => $depot]);

    expect(collect($whiteBoards)->pluck('code')->first())->toBe('MDF-BR-0001')
        ->and(collect($whiteBoards)->pluck('code')->last())->toBe('MDF-BR-0020');

    // Produção de 4 armários consumindo a receita.
    $cabinets = furnitureItems(['product_id' => $cabinet['id'], 'quantity' => 4, 'storage_id' => $showroom, 'consume_components' => true]);

    expect(collect($cabinets)->pluck('code')->all())->toBe(['ARM-A-0001', 'ARM-A-0002', 'ARM-A-0003', 'ARM-A-0004'])
        ->and(collect($cabinets)->pluck('expires_at')->unique()->all())->toBe([null]);

    // Operação: 4 armários = uma linha com 4; o lote de parafusos aparece direto.
    expect(furnitureStock())->toBe([
        'Armário modelo A' => '4.0000',
        'Chapa MDF branca' => '4.0000',
        'Chapa MDF castanho' => '2.0000',
        'PAR-0001' => '600.0000',
    ]);

    // Sobram 4 chapas brancas: dá para só mais 1 armário (a primeira que falta é a branca).
    $this->postJson('/api/items', ['product_id' => $cabinet['id'], 'quantity' => 2, 'consume_components' => true])
        ->assertUnprocessable()
        ->assertJsonPath('message.pt-br', 'Estoque insuficiente de "Chapa MDF branca". Necessário: 8, Disponível: 4.');

    expect(furnitureStock()['PAR-0001'])->toBe('600.0000');

    // Encontrar um armário específico pelo código e mudá-lo de lugar.
    $found = $this->getJson('/api/items?search=ARM-A-0003')->json('data.items');
    expect($found)->toHaveCount(1)->and($found[0]['storage']['name'])->toBe('Showroom');

    $this->patchJson("/api/items/{$found[0]['id']}", ['storage_id' => $depot])->assertOk();

    // Venda de 1 armário: o pacote diz qual pegar e a entrega tira só ele do estoque.
    $package = $this->postJson('/api/delivery-packages', ['items' => [['product_id' => $cabinet['id'], 'quantity' => 1]], 'notes' => 'Cliente: Ana'])
        ->assertCreated()
        ->json('data');

    expect($package['items'][0]['allocations'][0]['code'])->toBe('ARM-A-0001');

    $this->postJson("/api/delivery-packages/{$package['id']}/deliver")->assertOk();

    expect(furnitureStock()['Armário modelo A'])->toBe('3.0000')
        ->and(collect($this->getJson("/api/items?product_id={$cabinet['id']}")->json('data.items'))->pluck('code')->all())
        ->toBe(['ARM-A-0002', 'ARM-A-0003', 'ARM-A-0004']);

    // Produto com estoque não pode ser excluído nem trocar de modo.
    $this->deleteJson("/api/products/{$screw['id']}")->assertStatus(409);
});
