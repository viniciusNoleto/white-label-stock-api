<?php

namespace App\Repositories\Inventory;

use App\Base\Repository\BaseRepository;
use App\DTO\Inventory\InventoryItem\ListInventoryItemsDTO;
use App\Models\Inventory\InventoryItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class InventoryItemRepository extends BaseRepository {

    public function __construct() {
        $this->setModel(InventoryItem::class);
    }

    /**
     * @param ListInventoryItemsDTO $dto
     * @return LengthAwarePaginator
     */
    public function filterAndPaginate(ListInventoryItemsDTO $dto): LengthAwarePaginator {
        return $this->model
            ->search($dto->search)
            ->with([
                'unit',
                'storage',
                'categories' => fn($query) => $query->orderBy('name'),
                'components' => fn($query) => $query->orderBy('name'),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(perPage: $dto->per_page, page: $dto->page);
    }

    /**
     * @param int $inventory_item_id
     * @param int[] $category_ids
     * @return void
     */
    public function attachCategories(int $inventory_item_id, array $category_ids): void {
        if (!$category_ids) {
            return;
        }

        $this->find($inventory_item_id)->categories()->attach($category_ids, ['created_at' => now()]);
    }

    /**
     * @param int $inventory_item_id
     * @param array<int, array{id: int, quantity_required: string}> $components
     * @return void
     */
    public function attachComponents(int $inventory_item_id, array $components): void {
        if (!$components) {
            return;
        }

        $this->find($inventory_item_id)->components()->attach(
            collect($components)->mapWithKeys(fn($component) => [
                $component['id'] => ['quantity_required' => $component['quantity_required'], 'created_at' => now()],
            ])->all()
        );
    }

    /**
     * Receita do item: `component_inventory_item_id` + `quantity_required` por componente.
     *
     * @param int $inventory_item_id
     * @return Collection
     */
    public function recipe(int $inventory_item_id): Collection {
        return $this->model->getConnection()
            ->table('inventory_item_has_component')
            ->where('inventory_item_id', $inventory_item_id)
            ->get(['component_inventory_item_id', 'quantity_required']);
    }

    /**
     * Trava as linhas até o fim da transação (sempre na mesma ordem, evitando deadlock).
     *
     * @param int[] $ids
     * @return Collection<int, InventoryItem>
     */
    public function lockForUpdate(array $ids): Collection {
        return $this->model->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * Soma/subtrai no banco (atômico). Retorna as linhas afetadas.
     *
     * @param int $id
     * @param string $amount
     * @param array<string, mixed> $extra
     * @return int
     */
    public function incrementQuantity(int $id, string $amount, array $extra = []): int {
        return $this->model->whereKey($id)->increment('quantity', $amount, $extra);
    }

    /**
     * @param int $id
     * @param string $amount
     * @param array<string, mixed> $extra
     * @return int
     */
    public function decrementQuantity(int $id, string $amount, array $extra = []): int {
        return $this->model->whereKey($id)->decrement('quantity', $amount, $extra);
    }

    /**
     * @param int $id
     * @param array<string, mixed> $attributes
     * @return int
     */
    public function updateById(int $id, array $attributes): int {
        return $this->model->whereKey($id)->update($attributes);
    }
}
