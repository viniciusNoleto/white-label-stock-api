<?php

namespace App\Queries\Inventory;

use App\Data\Inventory\Item\ListItemsDTO;
use App\Enum\Inventory\ItemExpirationFilterEnum;
use App\Models\Inventory\Item;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ListItemsQuery {

    /**
     * Itens por código, nome ou SKU do produto; filtros por produto, armazenamento e validade.
     * Ordem: produto, depois FEFO (vence primeiro), depois código.
     *
     * @param ListItemsDTO $dto
     * @return LengthAwarePaginator
     */
    public function get(ListItemsDTO $dto): LengthAwarePaginator {
        $today = now()->toDateString();

        return Item::query()
            ->withReservedQuantity()
            ->join('products', 'products.id', '=', 'items.product_id')
            ->with(['product.unit', 'storage'])
            ->when($dto->search, fn(Builder $query, string $search) => $query->where(fn(Builder $query) => $query
                ->whereLike('items.code', '%' . $search . '%')
                ->orWhereLike('products.name', '%' . $search . '%')
                ->orWhereLike('products.sku', '%' . $search . '%')))
            ->when($dto->product_id, fn(Builder $query, int $id) => $query->where('items.product_id', $id))
            ->when($dto->storage_id, fn(Builder $query, int $id) => $query->where('items.storage_id', $id))
            ->when($dto->expiration, fn(Builder $query, ItemExpirationFilterEnum $expiration) => match ($expiration) {
                ItemExpirationFilterEnum::EXPIRED => $query->where('items.expires_at', '<', $today),
                ItemExpirationFilterEnum::EXPIRING => $query->whereBetween('items.expires_at', [$today, now()->addDays(Item::EXPIRING_DAYS)->toDateString()]),
                ItemExpirationFilterEnum::VALID => $query->where(fn(Builder $query) => $query->whereNull('items.expires_at')->orWhere('items.expires_at', '>=', $today)),
            })
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->firstExpiringFirst()
            ->orderBy('items.code')
            ->paginate(perPage: $dto->per_page, page: $dto->page);
    }
}
