<?php

namespace App\Actions\Inventory\Product;

use App\Exceptions\ApiException;
use App\Models\Inventory\Product;
use Illuminate\Database\QueryException;

final class DeleteProductAction {

    /**
     * Com itens, usado na receita de outro produto ou em pacotes de entrega (FK) → 409.
     * Categorias e a própria receita saem em cascata.
     *
     * @param mixed $id
     * @return void
     * @throws ApiException
     */
    public function handle(mixed $id): void {
        $product = Product::findOrFailByKey($id, 'messages.products.not_found');

        try {
            $product->delete();
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.products.in_use', 409);
            }

            throw $exception;
        }
    }
}
