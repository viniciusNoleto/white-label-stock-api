<?php

use App\Models\Delivery\DeliveryPackage;
use App\Models\Delivery\DeliveryPackageLine;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCategory;
use App\Models\Inventory\Product;
use App\Models\Inventory\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->withToken(accessToken());
});

function listProducts(string $query = ''): TestResponse {
    return test()->getJson("/api/products{$query}");
}

function productPayload(array $override = []): array {
    return [
        'name' => 'Armário modelo A',
        'unit_id' => Unit::factory()->create()->id,
        ...$override,
    ];
}

describe('GET /api/products', function () {
    it('returns an empty first page', function () {
        listProducts()
            ->assertOk()
            ->assertJsonPath('data', ['items' => [], 'meta' => ['page' => 1, 'per_page' => 8, 'total' => 0, 'last_page' => 1]]);
    });

    it('returns products with unit, categories, recipe and stock summary', function () {
        $unit = Unit::factory()->create(['name' => 'Unidade', 'abbreviation' => 'un']);
        $category = ItemCategory::factory()->create(['name' => 'Móveis', 'color_hex' => '#ff0000']);
        $screw = Product::factory()->bulk()->create(['name' => 'Parafuso', 'unit_id' => $unit->id]);
        $cabinet = Product::factory()->create(['name' => 'Armário', 'sku' => 'ARM-A', 'unit_id' => $unit->id, 'shelf_life_days' => 30]);
        $cabinet->categories()->attach($category->id);
        $cabinet->components()->attach($screw->id, ['quantity_required' => '100']);
        Item::factory()->create(['product_id' => $screw->id, 'quantity' => '250.5']);
        Item::factory()->count(2)->create(['product_id' => $cabinet->id]);
        $line = Item::factory()->create(['product_id' => $cabinet->id]);
        DeliveryPackageLine::create(['delivery_package_id' => DeliveryPackage::factory()->create()->id, 'product_id' => $cabinet->id, 'item_id' => $line->id, 'item_code' => $line->code, 'quantity' => '1']);

        [$first, $second] = listProducts()->json('data.items');

        expect(array_keys($first))->toBe(['id', 'name', 'sku', 'is_atomic', 'shelf_life_days', 'stock_quantity', 'reserved_quantity', 'expired_quantity', 'items_count', 'created_at', 'updated_at', 'unit', 'categories', 'components'])
            ->and($first)->toMatchArray([
                'id' => $cabinet->id,
                'name' => 'Armário',
                'sku' => 'ARM-A',
                'is_atomic' => true,
                'shelf_life_days' => 30,
                'stock_quantity' => '3.0000',
                'reserved_quantity' => '1.0000',
                'items_count' => 3,
                'unit' => ['id' => $unit->id, 'name' => 'Unidade', 'abbreviation' => 'un'],
                'categories' => [['id' => $category->id, 'name' => 'Móveis', 'color_hex' => '#ff0000']],
                'components' => [['id' => $screw->id, 'name' => 'Parafuso', 'is_atomic' => false, 'quantity_required' => '100.0000', 'stock_quantity' => '250.5000', 'reserved_quantity' => '0.0000', 'expired_quantity' => '0.0000']],
            ])
            ->and($second)->toMatchArray(['name' => 'Parafuso', 'is_atomic' => false, 'stock_quantity' => '250.5000', 'reserved_quantity' => '0.0000', 'items_count' => 1, 'components' => []]);
    });

    it('reports the expired quantity of each product', function () {
        $cake = Product::factory()->create(['name' => 'Bolo']);
        Item::factory()->create(['product_id' => $cake->id, 'expires_at' => now()->subDay()->toDateString()]);
        Item::factory()->create(['product_id' => $cake->id, 'expires_at' => now()->toDateString()]);
        Item::factory()->create(['product_id' => $cake->id]);

        expect(listProducts()->json('data.items.0'))->toMatchArray(['stock_quantity' => '3.0000', 'expired_quantity' => '1.0000']);
    });

    it('searches by name or SKU case-insensitively', function () {
        Product::factory()->create(['name' => 'Bolo de cenoura', 'sku' => 'BC']);
        Product::factory()->create(['name' => 'Torta', 'sku' => 'TORTA-LIMAO']);

        expect(collect(listProducts('?search=CENOURA')->json('data.items'))->pluck('name')->all())->toBe(['Bolo de cenoura'])
            ->and(collect(listProducts('?search=limao')->json('data.items'))->pluck('name')->all())->toBe(['Torta']);
    });

    it('paginates and clamps page/per_page', function () {
        foreach (['A', 'B', 'C'] as $name) {
            Product::factory()->create(['name' => $name]);
        }

        expect(listProducts('?page=2&per_page=2')->json('data.meta'))->toBe(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->and(listProducts('?page=0&per_page=500')->json('data.meta'))->toMatchArray(['page' => 1, 'per_page' => 100]);
    });

    it('returns 500 when the database fails', function () {
        failTable('products');

        listProducts()
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error fetching products.');
    });
});

describe('POST /api/products', function () {
    it('creates an atomic product by default, normalizing the SKU', function () {
        $payload = productPayload(['sku' => ' arm a ']);

        $this->postJson('/api/products', $payload)
            ->assertCreated()
            ->assertJsonPath('message.pt-br', 'Produto criado com sucesso.')
            ->assertJsonPath('data.name', 'Armário modelo A')
            ->assertJsonPath('data.sku', 'ARM-A')
            ->assertJsonPath('data.is_atomic', true)
            ->assertJsonPath('data.shelf_life_days', null)
            ->assertJsonPath('data.stock_quantity', '0.0000')
            ->assertJsonPath('data.items_count', 0)
            ->assertJsonPath('data.unit.id', $payload['unit_id']);
    });

    it('creates a bulk product with shelf life, categories and recipe', function () {
        $category = ItemCategory::factory()->create();
        $flour = Product::factory()->bulk()->create(['name' => 'Farinha']);
        $egg = Product::factory()->create(['name' => 'Ovo']);

        $response = $this->postJson('/api/products', productPayload([
            'name' => 'Massa',
            'is_atomic' => false,
            'shelf_life_days' => 3,
            'category_ids' => [$category->id, (string)$category->id],
            'components' => [['id' => $flour->id, 'quantity_required' => 0.5], ['id' => $egg->id, 'quantity_required' => 2]],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.is_atomic', false)
            ->assertJsonPath('data.shelf_life_days', 3)
            ->assertJsonPath('data.categories.0.id', $category->id)
            ->assertJsonPath('data.components', [
                ['id' => $flour->id, 'name' => 'Farinha', 'is_atomic' => false, 'quantity_required' => '0.5000', 'stock_quantity' => '0.0000', 'reserved_quantity' => '0.0000', 'expired_quantity' => '0.0000'],
                ['id' => $egg->id, 'name' => 'Ovo', 'is_atomic' => true, 'quantity_required' => '2.0000', 'stock_quantity' => '0.0000', 'reserved_quantity' => '0.0000', 'expired_quantity' => '0.0000'],
            ]);
    });

    it('requires name and unit', function (array $payload) {
        $this->postJson('/api/products', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Nome e unidade são obrigatórios.');
    })->with([
        [['name' => 'x']],
        [['unit_id' => 1]],
        [['name' => '  ', 'unit_id' => 1]],
    ]);

    it('rejects an invalid shelf life', function (mixed $days) {
        $this->postJson('/api/products', productPayload(['shelf_life_days' => $days]))
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Shelf life must be a whole number of days greater than zero.');
    })->with([0, -1, 1.5, 'abc', 36501]);

    it('rejects an invalid or repeated SKU', function () {
        $this->postJson('/api/products', productPayload(['sku' => 'A/B']))
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'Invalid SKU. Use up to 40 letters, numbers, dots, hyphens or underscores.');

        Product::factory()->create(['sku' => 'ARM-A']);

        $this->postJson('/api/products', productPayload(['sku' => 'arm-a']))
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Já existe um produto com este SKU.');
    });

    it('rejects invalid components', function (mixed $components, string $message) {
        $this->postJson('/api/products', productPayload(['components' => $components]))
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', $message);
    })->with([
        'not a list' => ['x', 'Each component needs a product and a quantity greater than zero.'],
        'no quantity' => [[['id' => 1]], 'Each component needs a product and a quantity greater than zero.'],
        'zero quantity' => [[['id' => 1, 'quantity_required' => 0]], 'Each component needs a product and a quantity greater than zero.'],
        'repeated' => [[['id' => 1, 'quantity_required' => 1], ['id' => 1, 'quantity_required' => 2]], 'Each product can only appear once in the recipe.'],
    ]);

    it('only accepts whole quantities of atomic components', function () {
        $board = Product::factory()->create(['name' => 'Chapa MDF']);

        $this->postJson('/api/products', productPayload(['components' => [['id' => $board->id, 'quantity_required' => 1.5]]]))
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'O componente "Chapa MDF" é contado por unidade: use uma quantidade inteira.');
    });

    it('returns 422 for references that do not exist and creates nothing', function (array $override) {
        $this->postJson('/api/products', productPayload($override))
            ->assertUnprocessable()
            ->assertJsonPath('message.en-us', 'The given unit, category or component does not exist.');

        expect(Product::count())->toBe(0);
    })->with([
        'unit' => [['unit_id' => 999]],
        'category' => [['category_ids' => [999]]],
        'component' => [['components' => [['id' => 999, 'quantity_required' => 1]]]],
    ]);

    it('returns 500 when the database fails', function () {
        $payload = productPayload();
        failTable('products');

        $this->postJson('/api/products', $payload)
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error creating product.');
    });
});

describe('PUT /api/products/{product_id}', function () {
    it('replaces data, categories and recipe, keeping the remaining categories', function () {
        $old = ItemCategory::factory()->create();
        $kept = ItemCategory::factory()->create();
        $new = ItemCategory::factory()->create();
        $screw = Product::factory()->bulk()->create(['name' => 'Parafuso']);
        $board = Product::factory()->create(['name' => 'Chapa']);
        $product = Product::factory()->create();
        $product->categories()->attach([$old->id, $kept->id], ['created_at' => now()->subDay()]);
        $product->components()->attach($screw->id, ['quantity_required' => '10']);

        $this->putJson("/api/products/{$product->id}", [
            'name' => 'Armário B',
            'sku' => 'ARM-B',
            'unit_id' => $product->unit_id,
            'shelf_life_days' => 365,
            'category_ids' => [$kept->id, $new->id],
            'components' => [['id' => $screw->id, 'quantity_required' => 100], ['id' => $board->id, 'quantity_required' => 4]],
        ])
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Produto atualizado com sucesso.')
            ->assertJsonPath('data.name', 'Armário B')
            ->assertJsonPath('data.sku', 'ARM-B')
            ->assertJsonPath('data.shelf_life_days', 365)
            ->assertJsonPath('data.components.0.quantity_required', '4.0000')
            ->assertJsonPath('data.components.1.quantity_required', '100.0000');

        $categories = DB::table('product_has_category')->where('product_id', $product->id)->orderBy('item_category_id')->get();

        expect($categories->pluck('item_category_id')->all())->toBe([$kept->id, $new->id])
            ->and($categories->first()->created_at)->toBe(now()->subDay()->format('Y-m-d H:i:s'));
    });

    it('does not let the recipe use the product itself, directly or indirectly', function () {
        $a = Product::factory()->create(['name' => 'A']);
        $b = Product::factory()->create(['name' => 'B']);
        $c = Product::factory()->create(['name' => 'C']);
        $b->components()->attach($c->id, ['quantity_required' => '1']);
        $c->components()->attach($a->id, ['quantity_required' => '1']);

        foreach ([$a->id, $b->id] as $component) {
            $this->putJson("/api/products/{$a->id}", ['name' => 'A', 'unit_id' => $a->unit_id, 'components' => [['id' => $component, 'quantity_required' => 1]]])
                ->assertUnprocessable()
                ->assertJsonPath('message.en-us', 'The recipe cannot use the product itself, directly or indirectly.');
        }
    });

    it('switches between atomic and bulk only while there are no items', function () {
        $product = Product::factory()->create();

        $this->putJson("/api/products/{$product->id}", ['name' => 'X', 'unit_id' => $product->unit_id, 'is_atomic' => false])
            ->assertOk()
            ->assertJsonPath('data.is_atomic', false);

        Item::factory()->create(['product_id' => $product->id]);

        $this->putJson("/api/products/{$product->id}", ['name' => 'X', 'unit_id' => $product->unit_id, 'is_atomic' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message.pt-br', 'Não é possível trocar entre atômico e a granel enquanto o produto tiver itens.');
    });

    it('does not change the expiration of items already generated', function () {
        $product = Product::factory()->create(['shelf_life_days' => 10]);
        $item = Item::factory()->create(['product_id' => $product->id, 'produced_at' => '2026-01-01', 'expires_at' => '2026-01-11']);

        $this->putJson("/api/products/{$product->id}", ['name' => 'X', 'unit_id' => $product->unit_id, 'shelf_life_days' => 2])->assertOk();

        expect($item->fresh()->expires_at->toDateString())->toBe('2026-01-11');
    });

    it('returns 404 for an unknown product', function (string $id) {
        $this->putJson("/api/products/{$id}", productPayload())
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Produto não encontrado.');
    })->with(['404', 'abc']);

    it('returns 500 when the database fails', function () {
        $product = Product::factory()->create();
        failTable('product_has_category');

        $this->putJson("/api/products/{$product->id}", ['name' => 'X', 'unit_id' => $product->unit_id])
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error updating product.');
    });
});

describe('DELETE /api/products/{product_id}', function () {
    it('deletes a product with its categories and recipe', function () {
        $screw = Product::factory()->bulk()->create();
        $product = Product::factory()->create();
        $product->categories()->attach(ItemCategory::factory()->create()->id);
        $product->components()->attach($screw->id, ['quantity_required' => '1']);

        $this->deleteJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('message.pt-br', 'Produto excluído com sucesso.');

        expect(Product::find($product->id))->toBeNull()
            ->and(DB::table('product_has_component')->count())->toBe(0)
            ->and(DB::table('product_has_category')->count())->toBe(0);
    });

    it('returns 409 when the product is in use', function (string $usage) {
        $product = Product::factory()->create();

        match ($usage) {
            'items' => Item::factory()->create(['product_id' => $product->id]),
            'recipe' => Product::factory()->create()->components()->attach($product->id, ['quantity_required' => '1']),
        };

        $this->deleteJson("/api/products/{$product->id}")
            ->assertStatus(409)
            ->assertJsonPath('message.en-us', 'This product has items, is part of another product\'s recipe or is in delivery packages.');
    })->with(['items', 'recipe']);

    it('returns 404 for an unknown product', function () {
        $this->deleteJson('/api/products/404')
            ->assertNotFound()
            ->assertJsonPath('message.pt-br', 'Produto não encontrado.');
    });

    it('returns 500 when the database fails', function () {
        failTable('products');

        $this->deleteJson('/api/products/1')
            ->assertInternalServerError()
            ->assertJsonPath('message.en-us', 'Error deleting product.');
    });
});
