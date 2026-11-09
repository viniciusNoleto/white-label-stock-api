<?php

namespace App\Queries\Delivery;

use App\Data\Delivery\DeliveryPackage\ListDeliveryPackagesDTO;
use App\Models\Delivery\DeliveryPackage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListDeliveryPackagesQuery {

    /**
     * Linhas (itens reservados) carregadas em toda resposta de pacote, com o produto, a unidade e o estoque dele.
     *
     * @return array<string|int, mixed>
     */
    public static function relations(): array {
        return [
            'lines' => fn($query) => $query->orderBy('id'),
            'lines.product' => fn($query) => $query->withStock()->with('unit'),
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
