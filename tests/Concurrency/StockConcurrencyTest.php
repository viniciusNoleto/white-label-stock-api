<?php

use App\Actions\Delivery\DeliveryPackage\CreateDeliveryPackageAction;
use App\Actions\Delivery\DeliveryPackage\DeliverDeliveryPackageAction;
use App\Actions\Inventory\Item\CreateItemsAction;
use App\Data\Delivery\DeliveryPackage\SaveDeliveryPackageDTO;
use App\Data\Inventory\Item\CreateItemsDTO;
use App\Models\Delivery\DeliveryPackage;
use App\Models\Inventory\Item;
use App\Models\Inventory\Product;
use Illuminate\Support\Facades\DB;

/*
| Corridas reais entre processos, contra o Postgres: o que os testes de feature (uma requisição
| por vez, dentro de uma transação) não conseguem provar.
*/

function package(array $items): SaveDeliveryPackageDTO {
    return new SaveDeliveryPackageDTO(recipient: null, notes: null, items: array_map(
        fn(array $item) => ['product_id' => $item[0], 'quantity' => to_decimal($item[1]), 'item_ids' => null],
        $items,
    ));
}

it('never reserves the same unit twice when many packages race for the last items', function () {
    $product = Product::factory()->create(['sku' => 'BOLO']);
    Item::factory()->count(5)->sequence(fn($sequence) => ['code' => 'BOLO-' . ($sequence->index + 1)])->create(['product_id' => $product->id]);

    $results = runConcurrently(12, fn() => app(CreateDeliveryPackageAction::class)->handle(package([[$product->id, 1]]), null)->id);

    $ok = collect($results)->where('ok', true);
    $failed = collect($results)->where('ok', false);

    expect($ok)->toHaveCount(5)
        ->and($failed->pluck('key')->unique()->values()->all())->toBe(['messages.delivery_packages.insufficient_stock'])
        ->and(DB::table('delivery_package_has_item')->count())->toBe(5)
        ->and(DB::table('delivery_package_has_item')->distinct()->count('item_id'))->toBe(5);
});

it('does not deadlock when deliveries and reservations lock the same items from opposite sides', function () {
    // Produto A tem id menor, mas os itens de B têm ids menores: quem trava "por id do item" pega B
    // primeiro, quem trava "por produto" pega A primeiro. Antes da ordem global de locks
    // (`Item::lockedInStockOrder`), entrega × reserva cruzavam e o Postgres matava uma (500).
    $a = Product::factory()->create(['sku' => 'A']);
    $b = Product::factory()->create(['sku' => 'B']);
    Item::factory()->count(30)->sequence(fn($s) => ['code' => 'B-' . $s->index])->create(['product_id' => $b->id]);
    Item::factory()->count(30)->sequence(fn($s) => ['code' => 'A-' . $s->index])->create(['product_id' => $a->id]);

    $packages = collect(range(1, 8))
        ->map(fn() => app(CreateDeliveryPackageAction::class)->handle(package([[$a->id, 1], [$b->id, 1]]), null)->id)
        ->all();

    // Sem retry: um deadlock apareceria como falha (`40P01`) em vez de ser absorvido por uma nova tentativa.
    $results = withoutTransactionRetries(fn() => runConcurrently(16, fn(int $worker) => $worker % 2 === 0
        ? app(DeliverDeliveryPackageAction::class)->handle($packages[intdiv($worker, 2)])->id
        : app(CreateDeliveryPackageAction::class)->handle(package([[$a->id, 1], [$b->id, 1]]), null)->id));

    expect(collect($results)->where('ok', false)->all())->toBe([])
        ->and(DeliveryPackage::where('status', 'delivered')->count())->toBe(8)
        // 8 entregues (16 itens saíram), 8 pendentes novos reservando 16 dos 44 restantes, sem repetir.
        ->and(Item::count())->toBe(44)
        ->and(DB::table('delivery_package_has_item')->join('delivery_packages', 'delivery_packages.id', '=', 'delivery_package_id')
            ->where('status', 'pending')->distinct()->count('item_id'))->toBe(16);
});

it('never subtracts a bulk lot below zero when deliveries race', function () {
    $product = Product::factory()->bulk()->create(['sku' => 'COCO']);
    Item::factory()->create(['product_id' => $product->id, 'code' => 'COCO-L1', 'quantity' => '10']);

    $packages = collect(range(1, 5))
        ->map(fn() => app(CreateDeliveryPackageAction::class)->handle(package([[$product->id, 2]]), null)->id)
        ->all();

    // Alguém baixa a quantidade do lote por fora (ajuste manual direto no banco, simulado).
    DB::table('items')->where('code', 'COCO-L1')->update(['quantity' => '7']);

    $results = runConcurrently(5, fn(int $worker) => app(DeliverDeliveryPackageAction::class)->handle($packages[$worker])->id);

    expect(collect($results)->where('ok', true))->toHaveCount(3)
        ->and(collect($results)->where('ok', false)->pluck('key')->unique()->values()->all())->toBe(['messages.items.insufficient_stock'])
        ->and(Item::where('code', 'COCO-L1')->value('quantity'))->toBe('1.0000');
});

it('does not deadlock when production with a recipe races deliveries of the component and the product', function () {
    // Produção de P consumindo C × entrega de pacotes com C e P. A produção trava a linha de P (sequência
    // dos códigos) e depois os itens de C; a entrega trava os itens de C e P e, ao gravar a movimentação de P,
    // a FK pede `FOR KEY SHARE` na linha de P. Com `FOR UPDATE` na linha do produto isso fecha um ciclo;
    // com `FOR NO KEY UPDATE` (que não conflita com `FOR KEY SHARE`) não há espera, logo não há ciclo.
    $component = Product::factory()->create(['sku' => 'C']);
    $product = Product::factory()->create(['sku' => 'P']);
    $product->components()->attach($component->id, ['quantity_required' => '1.0000']);
    Item::factory()->count(40)->sequence(fn($s) => ['code' => 'C-' . $s->index])->create(['product_id' => $component->id]);
    Item::factory()->count(10)->sequence(fn($s) => ['code' => 'P-' . $s->index])->create(['product_id' => $product->id]);

    $packages = collect(range(1, 10))
        ->map(fn() => app(CreateDeliveryPackageAction::class)->handle(package([[$component->id, 1], [$product->id, 1]]), null)->id)
        ->all();

    $results = withoutTransactionRetries(fn() => runConcurrently(20, fn(int $worker) => $worker % 2 === 0
        ? app(DeliverDeliveryPackageAction::class)->handle($packages[intdiv($worker, 2)])->id
        : app(CreateItemsAction::class)->handle(new CreateItemsDTO(
            product_id: $product->id,
            quantity: '1.0000',
            produced_at: business_today(),
            storage_id: null,
            consume_components: true,
        ))->count()));

    expect(collect($results)->where('ok', false)->all())->toBe([])
        ->and(DeliveryPackage::where('status', 'delivered')->count())->toBe(10)
        // 40 C − 10 entregues − 10 consumidos; 10 P − 10 entregues + 10 produzidos.
        ->and(Item::where('product_id', $component->id)->count())->toBe(20)
        ->and(Item::where('product_id', $product->id)->count())->toBe(10);
});
