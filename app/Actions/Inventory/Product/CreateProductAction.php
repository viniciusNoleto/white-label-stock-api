<?php

namespace App\Actions\Inventory\Product;

use App\Base\Cache\DashboardCache;
use App\Data\Inventory\Product\SaveProductDTO;
use App\Exceptions\ApiException;
use App\Models\Inventory\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateProductAction {

    public function __construct(
        private readonly SyncProductRelationsAction $syncRelations,
    ) {
    }

    /**
     * Produto + categorias + receita numa transação: referência inexistente não grava nada.
     *
     * @param SaveProductDTO $dto
     * @return Product
     * @throws Throwable
     */
    public function handle(SaveProductDTO $dto): Product {
        try {
            return DB::transaction(function () use ($dto) {
                DashboardCache::invalidate();

                $product = Product::create([
                    'name' => $dto->name,
                    'sku' => $dto->sku,
                    'unit_id' => $dto->unit_id,
                    'is_atomic' => $dto->is_atomic,
                    'shelf_life_days' => $dto->shelf_life_days,
                ]);

                $this->syncRelations->handle($product, $dto);

                return Product::query()->withStock()->with([...Product::RESPONSE_RELATIONS, 'components' => fn($query) => $query->withStock()])->findOrFail($product->id);
            }, attempts: config('database.transaction_attempts'));
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.products.invalid_reference');
            }

            if (is_unique_violation($exception)) {
                throw new ApiException('messages.products.sku_taken');
            }

            throw $exception;
        }
    }
}
