<?php

namespace App\Actions\Inventory\Product;

use App\Data\Inventory\Product\SaveProductDTO;
use App\Exceptions\ApiException;
use App\Models\Inventory\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UpdateProductAction {

    private const NOT_FOUND = 'messages.products.not_found';

    public function __construct(
        private readonly SyncProductRelationsAction $syncRelations,
    ) {
    }

    /**
     * Substitui todos os dados do produto. Atômico ↔ a granel só enquanto não houver itens
     * (os registros existentes deixariam de fazer sentido). Mudar a validade não altera o
     * vencimento dos itens já gerados.
     *
     * @param mixed $id
     * @param SaveProductDTO $dto
     * @return Product
     * @throws Throwable
     */
    public function handle(mixed $id, SaveProductDTO $dto): Product {
        $product_id = parse_id($id) ?? throw new ModelNotFoundException(self::NOT_FOUND);

        try {
            return DB::transaction(function () use ($product_id, $dto) {
                $product = Product::query()->lockedForWrite()->find($product_id)
                    ?? throw new ModelNotFoundException(self::NOT_FOUND);

                if ($product->is_atomic !== $dto->is_atomic && $product->items()->exists()) {
                    throw new ApiException('messages.products.mode_locked');
                }

                $product->update([
                    'name' => $dto->name,
                    'sku' => $dto->sku,
                    'unit_id' => $dto->unit_id,
                    'is_atomic' => $dto->is_atomic,
                    'shelf_life_days' => $dto->shelf_life_days,
                ]);
                $product->touch();

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
