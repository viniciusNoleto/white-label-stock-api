<?php

namespace App\Actions\Inventory\Item;

use App\Exceptions\ApiException;
use App\Models\Inventory\Item;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeleteItemAction {

    private const NOT_FOUND = 'messages.items.not_found';

    /**
     * Baixa manual (perda, vencido, quebrado). Reservado em pacote pendente → 409: tire do pacote antes.
     * Linhas de pacotes já entregues guardam o código e ficam.
     *
     * @param mixed $id
     * @return void
     * @throws Throwable
     */
    public function handle(mixed $id): void {
        $item_id = parse_id($id) ?? throw new ModelNotFoundException(self::NOT_FOUND);

        DB::transaction(function () use ($item_id) {
            $item = Item::query()->lockForUpdate()->find($item_id) ?? throw new ModelNotFoundException(self::NOT_FOUND);

            if (Item::reservedLinesQuery()->where('delivery_package_has_item.item_id', $item->id)->exists()) {
                throw new ApiException('messages.items.reserved', 409);
            }

            $item->delete();
        });
    }
}
