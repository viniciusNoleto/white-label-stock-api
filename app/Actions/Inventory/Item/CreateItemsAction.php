<?php

namespace App\Actions\Inventory\Item;

use App\Actions\Inventory\StockMovement\RecordStockMovementsAction;
use App\Data\Inventory\Item\CreateItemsDTO;
use App\Enum\Inventory\StockMovementTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Inventory\Item;
use App\Models\Inventory\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateItemsAction {

    /** Limite de itens atômicos gerados numa única produção. */
    public const MAX_BATCH = 500;

    private const SCALE = 4;

    public function __construct(
        private readonly AllocateProductStockAction $allocate,
        private readonly ConsumeItemStockAction     $consume,
        private readonly RecordStockMovementsAction $recordMovements,
    ) {
    }

    /**
     * Gera itens de um produto (produção ou entrada no estoque).
     * Atômico: `quantity` itens, cada um com código próprio (`{SKU}-0001`…) — produção em lote.
     * O código é sempre gerado aqui (nunca vem do cliente).
     * A granel: um registro (lote) com a `quantity`.
     * Vencimento = `produced_at` + validade do produto. Com `consume_components`, a receita sai do
     * estoque (FEFO) na mesma transação: faltou componente, nada é gravado.
     * Cada item gerado vira uma movimentação de entrada (ou produção) e cada componente gasto, uma de consumo.
     *
     * @param CreateItemsDTO $dto
     * @return Collection<int, Item>
     * @throws Throwable
     */
    public function handle(CreateItemsDTO $dto): Collection {
        try {
            return DB::transaction(function () use ($dto) {
                // A linha do produto travada serializa a sequência dos códigos.
                $product = Product::query()->lockForUpdate()->find($dto->product_id)
                    ?? throw new ApiException('messages.items.invalid_reference');

                $count = $this->itemsCount($product, $dto);

                if ($dto->consume_components) {
                    $this->consumeRecipe($product, $dto->quantity);
                }

                $produced_at = Carbon::parse($dto->produced_at)->startOfDay();
                $expires_at = $product->shelf_life_days ? $produced_at->copy()->addDays($product->shelf_life_days) : null;
                $codes = $this->nextCodes($product, $count);
                $now = now();

                Item::query()->insert(array_map(fn(string $code) => [
                    'product_id' => $product->id,
                    'code' => $code,
                    'quantity' => $product->is_atomic ? '1' : $dto->quantity,
                    'storage_id' => $dto->storage_id,
                    'produced_at' => $produced_at->toDateString(),
                    'expires_at' => $expires_at?->toDateString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $codes));

                $items = Item::query()
                    ->withReservedQuantity()
                    ->with(['product.unit', 'storage'])
                    ->whereIn('code', $codes)
                    ->orderBy('id')
                    ->get();

                $this->recordMovements->handle(
                    $dto->consume_components ? StockMovementTypeEnum::PRODUCTION : StockMovementTypeEnum::ENTRY,
                    $product,
                    $items->map(fn(Item $item) => ['item' => $item, 'quantity' => $item->quantity, 'balance' => $item->quantity])->all(),
                );

                return $items;
            });
        } catch (QueryException $exception) {
            if (is_foreign_key_violation($exception)) {
                throw new ApiException('messages.items.invalid_reference');
            }

            if (is_unique_violation($exception)) {
                throw new ApiException('messages.items.code_taken');
            }

            throw $exception;
        }
    }

    /**
     * Quantos registros serão criados (atômico: um por unidade; a granel: um lote).
     *
     * @param Product $product
     * @param CreateItemsDTO $dto
     * @return int
     * @throws ApiException
     */
    private function itemsCount(Product $product, CreateItemsDTO $dto): int {
        if (!$product->is_atomic) {
            return 1;
        }

        if (!is_whole_number($dto->quantity, self::SCALE) || bccomp($dto->quantity, (string)self::MAX_BATCH, self::SCALE) > 0) {
            throw new ApiException('messages.items.atomic_batch', 422, ['max' => self::MAX_BATCH]);
        }

        return (int)$dto->quantity;
    }

    /**
     * Consome `quantity_required × quantity` de cada componente, sempre na ordem do id (evita deadlock).
     *
     * @param Product $product
     * @param string $quantity
     * @return void
     * @throws ApiException
     */
    private function consumeRecipe(Product $product, string $quantity): void {
        $components = $product->components()->orderBy('products.id')->get();

        if ($components->isEmpty()) {
            throw new ApiException('messages.items.no_components');
        }

        foreach ($components as $component) {
            $required = bcmul($component->pivot->quantity_required, $quantity, self::SCALE);

            $allocations = $this->allocate->handle($component, $required);

            // Registra antes de consumir: o atômico é excluído no consumo.
            $this->recordMovements->handle(StockMovementTypeEnum::CONSUMPTION, $component, array_map(fn(array $allocation) => [
                'item' => $allocation['item'],
                'quantity' => bcmul($allocation['quantity'], '-1', self::SCALE),
                'balance' => bcsub($allocation['item']->quantity, $allocation['quantity'], self::SCALE),
            ], $allocations));

            foreach ($allocations as $allocation) {
                $this->consume->handle($allocation['item'], $component, $allocation['quantity']);
            }
        }
    }

    /**
     * Próximos códigos da sequência do produto, pulando os já usados (ex.: códigos antigos digitados à mão).
     *
     * @param Product $product
     * @param int $count
     * @return string[]
     */
    private function nextCodes(Product $product, int $count): array {
        $prefix = $product->codePrefix();
        $number = $product->next_item_number;
        $codes = [];

        while (count($codes) < $count) {
            $candidates = [];

            for ($i = 0; $i < $count - count($codes); $i++) {
                $candidates[] = sprintf('%s-%04d', $prefix, $number++);
            }

            $taken = Item::query()->whereIn('code', $candidates)->pluck('code')->all();
            $codes = array_merge($codes, array_values(array_diff($candidates, $taken)));
        }

        $product->update(['next_item_number' => $number]);

        return $codes;
    }
}
