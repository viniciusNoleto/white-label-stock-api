<?php

namespace App\Queries\Delivery;

use App\Data\Delivery\DeliveryPackage\ListDeliveryPackagesDTO;
use App\Models\Delivery\DeliveryPackage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListDeliveryPackagesQuery {

    /**
     * Itens carregados em toda resposta de pacote (ordenados por nome, com unidade).
     *
     * @return array<string|int, mixed>
     */
    public static function relations(): array {
        return [
            'items' => fn($query) => $query->with('unit')->orderBy('name')->orderBy('inventory_items.id'),
        ];
    }

    /**
     * Filtro opcional por status, mais recentes primeiro.
     *
     * @param ListDeliveryPackagesDTO $dto
     * @return LengthAwarePaginator
     */
    public function get(ListDeliveryPackagesDTO $dto): LengthAwarePaginator {
        return DeliveryPackage::query()
            ->ofStatus($dto->status)
            ->with(self::relations())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $dto->per_page, page: $dto->page);
    }
}
