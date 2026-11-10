<?php

namespace App\Queries\Inventory;

use App\Data\Inventory\StockMovement\ListStockMovementsDTO;
use App\Enum\Inventory\StockMovementTypeEnum;
use App\Enum\Inventory\WriteOffReasonEnum;
use App\Models\Inventory\StockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class ListStockMovementsQuery {

    /**
     * Mais recentes primeiro. Busca no código do item ou no nome do produto (sem diferenciar
     * maiúsculas); filtros por tipo, motivo da baixa, produto, armazenamento (na transferência, só o
     * destino) e intervalo de datas (dias inteiros no fuso do negócio, convertidos para o UTC gravado).
     *
     * @param ListStockMovementsDTO $dto
     * @return LengthAwarePaginator
     */
    public function get(ListStockMovementsDTO $dto): LengthAwarePaginator {
        return StockMovement::query()
            ->when($dto->search, fn(Builder $query, string $search) => $query->where(fn(Builder $query) => $query
                ->whereLike('item_code', '%' . escape_like($search) . '%')
                ->orWhereLike('product_name', '%' . escape_like($search) . '%')))
            ->when($dto->type, fn(Builder $query, StockMovementTypeEnum $type) => $query->where('type', $type->value))
            ->when($dto->reason, fn(Builder $query, WriteOffReasonEnum $reason) => $query->where('reason', $reason->value))
            ->when($dto->product_id, fn(Builder $query, int $id) => $query->where('product_id', $id))
            ->when($dto->storage_id, fn(Builder $query, int $id) => $query->where('storage_id', $id))
            ->when($dto->date_from, fn(Builder $query, string $date) => $query->where('created_at', '>=', business_day_start_utc($date)))
            ->when($dto->date_to, fn(Builder $query, string $date) => $query->where('created_at', '<', business_day_start_utc(Carbon::parse($date)->addDay()->toDateString())))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $dto->per_page, page: $dto->page);
    }
}
