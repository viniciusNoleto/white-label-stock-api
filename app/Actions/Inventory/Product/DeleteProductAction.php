<?php

namespace App\Actions\Inventory\Product;

use App\Exceptions\ApiException;
use App\Models\Inventory\Item;
use App\Models\Inventory\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeleteProductAction {

    /**
     * Com itens com saldo, usado na receita de outro produto ou em pacotes de entrega (FK) → 409.
     * Categorias e a própria receita saem em cascata.
     *
     * Lotes a granel **zerados** não impedem a exclusão: o lote a granel fica no estoque mesmo zerado
     * (pode receber ajuste depois), então sem isto um produto a granel com histórico nunca poderia sair.
     * Eles são apagados junto, na mesma transação; o histórico continua nas movimentações (código, produto
     * e quantidades copiados; as FKs viram `null`). Apagar um lote zerado não muda saldo, então não gera
     * movimentação. Se o produto ainda estiver em uso, o rollback devolve os lotes.
     *
     * @param mixed $id
     * @return void
     * @throws ApiException
     * @throws Throwable
     */
    public function handle(mixed $id): void {
        $product = Product::findOrFailByKey($id, 'messages.products.not_found');

        try {
            DB::transaction(function () use ($product) {
                Item::query()->where('product_id', $product->id)->where('quantity', 0)->delete();

                $product->delete();
            }, attempts: config('database.transaction_attempts'));
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.products.in_use', 409);
            }

            throw $exception;
        }
    }
}
