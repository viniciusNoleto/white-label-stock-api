<?php

namespace App\Actions\Inventory\Product;

use App\Data\Inventory\Product\SaveProductDTO;
use App\Exceptions\ApiException;
use App\Models\Inventory\Product;
use Illuminate\Support\Facades\DB;

final class SyncProductRelationsAction {

    private const SCALE = 4;

    /** Chave do `pg_advisory_xact_lock` que serializa as edições de receita. */
    private const RECIPE_LOCK_KEY = 7_301_001;

    /**
     * Substitui categorias e receita do produto (as categorias que continuam mantêm o `created_at`).
     * A receita não pode conter o próprio produto nem formar ciclo (A usa B que usa A), e componente
     * atômico só entra em quantidade inteira (não existe meio armário). Deve rodar numa transação.
     *
     * @param Product $product
     * @param SaveProductDTO $dto
     * @return void
     * @throws ApiException
     */
    public function handle(Product $product, SaveProductDTO $dto): void {
        $current = $product->categories()->pluck('item_categories.id')->all();

        $product->categories()->detach(array_values(array_diff($current, $dto->category_ids)));
        $product->categories()->attach(array_values(array_diff($dto->category_ids, $current)), ['created_at' => now()]);

        $this->validateRecipe($product, $dto->components);

        $product->components()->sync(
            collect($dto->components)->mapWithKeys(fn($component) => [
                $component['id'] => ['quantity_required' => $component['quantity_required'], 'created_at' => now()],
            ])->all()
        );
    }

    /**
     * @param Product $product
     * @param array<int, array{id: int, quantity_required: string}> $components
     * @return void
     * @throws ApiException
     */
    private function validateRecipe(Product $product, array $components): void {
        if (!$components) {
            return;
        }

        // Serializa as edições de receita: em READ COMMITTED, "A passa a usar B" e "B passa a usar A"
        // em paralelo enxergariam cada uma o grafo antigo e as duas passariam na busca de ciclo.
        // O advisory lock vale até o fim da transação e só concorre com outra edição de receita.
        DB::statement('SELECT pg_advisory_xact_lock(?)', [self::RECIPE_LOCK_KEY]);

        $ids = array_column($components, 'id');
        $found = Product::query()->whereIn('id', $ids)->get(['id', 'name', 'is_atomic'])->keyBy('id');

        if ($found->count() !== count($ids)) {
            throw new ApiException('messages.products.invalid_reference');
        }

        foreach ($components as $component) {
            $component_product = $found->get($component['id']);

            if ($component_product->is_atomic && !is_whole_number($component['quantity_required'], self::SCALE)) {
                throw new ApiException('messages.products.atomic_component_integer', 422, ['name' => $component_product->name]);
            }
        }

        if (in_array($product->id, $ids, true) || $this->reaches($ids, $product->id)) {
            throw new ApiException('messages.products.recipe_cycle');
        }
    }

    /**
     * Algum dos produtos (ou os componentes deles, recursivamente) usa `$target` na receita?
     *
     * @param int[] $from
     * @param int $target
     * @return bool
     */
    private function reaches(array $from, int $target): bool {
        $visited = [];
        $frontier = $from;

        while ($frontier) {
            $visited = array_merge($visited, $frontier);
            $next = DB::table('product_has_component')
                ->whereIn('product_id', $frontier)
                ->pluck('component_product_id')
                ->unique()
                ->all();

            if (in_array($target, $next, true)) {
                return true;
            }

            $frontier = array_values(array_diff($next, $visited));
        }

        return false;
    }
}
