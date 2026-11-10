<?php

namespace App\Queries\Inventory;

use App\Data\Inventory\Stock\ListStockDTO;
use App\Models\Inventory\Item;
use App\Models\Inventory\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tela de operação: o estoque pronto para ler.
 * - Produto atômico → **uma linha por produto** (`kind = group`) somando os itens (4 armários = 1 linha com 4).
 * - Produto a granel → **uma linha por lote** (`kind = record`), com a quantidade dele (lotes zerados ficam de fora).
 * Os filtros valem para os itens antes do agrupamento: a linha do produto soma só os itens que passaram.
 */
final class ListStockQuery {

    /**
     * @param ListStockDTO $dto
     * @return LengthAwarePaginator paginador de `['row' => object, 'product' => Product]`
     */
    public function get(ListStockDTO $dto): LengthAwarePaginator {
        $today = now()->toDateString();

        $groups = $this->base($dto)
            ->where('products.is_atomic', true)
            ->groupBy('products.id', 'products.name')
            ->selectRaw("
                'group' AS kind,
                products.id AS product_id,
                products.name AS product_name,
                NULL::bigint AS item_id,
                NULL::varchar AS item_code,
                SUM(items.quantity)::numeric(15, 4) AS quantity,
                COALESCE(SUM(reserved.total), 0)::numeric(15, 4) AS reserved_quantity,
                COUNT(*) AS items_count,
                MIN(items.expires_at)::text AS next_expires_at,
                COALESCE(SUM(items.quantity) FILTER (WHERE items.expires_at < ?), 0)::numeric(15, 4) AS expired_quantity,
                COALESCE(jsonb_agg(DISTINCT jsonb_build_object('id', storages.id, 'name', storages.name)) FILTER (WHERE storages.id IS NOT NULL), '[]'::jsonb)::text AS storages
            ", [$today]);

        // Lote zerado (consumido/entregue) não é estoque: fica só na tela de itens.
        $records = $this->base($dto)
            ->where('products.is_atomic', false)
            ->where('items.quantity', '>', 0)
            ->selectRaw("
                'record' AS kind,
                products.id AS product_id,
                products.name AS product_name,
                items.id AS item_id,
                items.code AS item_code,
                items.quantity::numeric(15, 4) AS quantity,
                COALESCE(reserved.total, 0)::numeric(15, 4) AS reserved_quantity,
                1 AS items_count,
                items.expires_at::text AS next_expires_at,
                (CASE WHEN items.expires_at < ? THEN items.quantity ELSE 0 END)::numeric(15, 4) AS expired_quantity,
                (CASE WHEN storages.id IS NULL THEN '[]'::jsonb ELSE jsonb_build_array(jsonb_build_object('id', storages.id, 'name', storages.name)) END)::text AS storages
            ", [$today]);

        $paginator = DB::query()
            ->fromSub($groups->unionAll($records), 'stock')
            ->orderBy('product_name')
            ->orderBy('product_id')
            ->orderBy('kind')
            ->orderByRaw('next_expires_at ASC NULLS LAST')
            ->orderBy('item_code')
            ->paginate(perPage: $dto->per_page, page: $dto->page);

        $products = Product::query()
            ->with('unit')
            ->whereIn('id', collect($paginator->items())->pluck('product_id')->unique()->all())
            ->get()
            ->keyBy('id');

        return $paginator->through(fn(object $row) => ['row' => $row, 'product' => $products->get($row->product_id)]);
    }

    /**
     * Itens com produto, armazenamento e o total reservado de cada um; busca pelo nome ou SKU do produto,
     * filtros por armazenamento e vencimento exato.
     *
     * @param ListStockDTO $dto
     * @return Builder
     */
    private function base(ListStockDTO $dto): Builder {
        $reserved = Item::reservedLinesQuery()
            ->groupBy('delivery_package_has_item.item_id')
            ->selectRaw('delivery_package_has_item.item_id, SUM(delivery_package_has_item.quantity) AS total');

        return DB::table('items')
            ->join('products', 'products.id', '=', 'items.product_id')
            ->leftJoin('storages', 'storages.id', '=', 'items.storage_id')
            ->leftJoinSub($reserved, 'reserved', 'reserved.item_id', '=', 'items.id')
            ->when($dto->search, fn(Builder $query, string $search) => $query->where(fn(Builder $match) => $match
                ->whereLike('products.name', '%' . $search . '%')
                ->orWhereLike('products.sku', '%' . $search . '%')))
            ->when($dto->storage_id, fn(Builder $query, int $id) => $query->where('items.storage_id', $id))
            ->when($dto->expires_at, fn(Builder $query, string $date) => $query->whereDate('items.expires_at', $date));
    }
}
