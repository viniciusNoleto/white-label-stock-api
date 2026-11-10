<?php

namespace App\Queries\Dashboard;

use App\Enum\Dashboard\DashboardPeriodEnum;
use App\Enum\Delivery\DeliveryPackageStatusEnum;
use App\Enum\Inventory\StockMovementTypeEnum;
use App\Enum\Inventory\WriteOffReasonEnum;
use App\Models\Delivery\DeliveryPackage;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Estatísticas do painel, calculadas direto do banco (sem cache: quem guarda é `GetDashboardQuery`).
 *
 * Dois tipos de número:
 * - **retrato de agora** (estoque, vencimentos, pacotes pendentes): não depende do período;
 * - **período** (`$period` dias terminando hoje, dias inteiros no fuso do negócio): saídas, perdas,
 *   entregas e atividade, lidos das movimentações (agrupadas pelos nomes copiados, como no resumo).
 *
 * Quantidades de produtos diferentes não são somadas (unidades diferentes): totais gerais são
 * **contagens** (itens, pacotes, movimentações) e quantidades aparecem sempre por produto, com a unidade.
 * Contas de quantidade em bcmath (`DECIMAL_SCALE`), como no resto da API.
 */
final class DashboardStatsQuery {

    /** Tamanho das listas do painel (reposição, vencimentos, mais entregues, perdas). */
    public const LIST_LIMIT = 8;

    /** Tipos que tiram do estoque para uso (o "consumo" que a reposição precisa cobrir). */
    private const OUTFLOW_TYPES = [StockMovementTypeEnum::DELIVERY, StockMovementTypeEnum::CONSUMPTION];

    /** Tipos que põem no estoque. */
    private const INFLOW_TYPES = [StockMovementTypeEnum::ENTRY, StockMovementTypeEnum::PRODUCTION];

    /**
     * @param DashboardPeriodEnum $period
     * @return array<string, mixed> no formato de `DashboardResource`
     */
    public function get(DashboardPeriodEnum $period): array {
        $days = $period->value;
        $today = business_today();
        $from = Carbon::parse($today)->subDays($days - 1)->toDateString();
        $from_utc = business_day_start_utc($from);
        $previous_from_utc = business_day_start_utc(Carbon::parse($from)->subDays($days)->toDateString());
        $expiring_until = Carbon::parse($today)->addDays(Item::EXPIRING_DAYS)->toDateString();

        $products = $this->products($today, $expiring_until, $from_utc);

        return [
            'generated_at' => now()->toISOString(),
            'period' => ['days' => $days, 'from' => $from, 'to' => $today],
            'stock' => $this->stock($products, $today, $expiring_until),
            'deliveries' => $this->deliveries($today, $from_utc, $previous_from_utc),
            'losses' => $this->losses($from_utc, $previous_from_utc),
            'activity' => $this->activity($from, $today, $from_utc),
            'top_delivered' => $this->topDelivered($from_utc),
            'restock' => $this->restock($products, $days),
            'expiration_alerts' => $this->expirationAlerts($products),
        ];
    }

    /**
     * Uma linha por produto: estoque livre (dentro da validade, menos o reservado em pacotes pendentes),
     * vencido, vencendo, próximo vencimento e a saída do período (entregas + consumo da receita).
     *
     * @return Collection<int, stdClass> linhas com `product_id`, `product_name`, `unit_abbreviation`, `is_atomic`, `usable_quantity`, `free_quantity`, `expired_quantity`, `expiring_quantity`, `next_expires_at` e `outflow_quantity` (quantidades em string)
     */
    private function products(string $today, string $expiring_until, Carbon $from_utc): Collection {
        $rows = DB::select('
            WITH params AS (
                SELECT ?::date AS today, ?::date AS expiring_until, ?::timestamp AS from_utc, ?::text AS pending
            ),
            stock AS (
                SELECT
                    items.product_id,
                    COALESCE(SUM(items.quantity) FILTER (WHERE items.expires_at IS NULL OR items.expires_at >= params.today), 0) AS usable,
                    COALESCE(SUM(items.quantity) FILTER (WHERE items.expires_at < params.today), 0) AS expired,
                    COALESCE(SUM(items.quantity) FILTER (WHERE items.expires_at BETWEEN params.today AND params.expiring_until), 0) AS expiring,
                    MIN(items.expires_at) FILTER (WHERE items.expires_at >= params.today) AS next_expires_at
                FROM items
                CROSS JOIN params
                WHERE items.quantity > 0
                GROUP BY items.product_id
            ),
            reserved AS (
                SELECT items.product_id, SUM(lines.quantity) AS quantity
                FROM delivery_package_has_item AS lines
                JOIN delivery_packages ON delivery_packages.id = lines.delivery_package_id
                JOIN items ON items.id = lines.item_id
                CROSS JOIN params
                WHERE delivery_packages.status = params.pending
                    AND (items.expires_at IS NULL OR items.expires_at >= params.today)
                GROUP BY items.product_id
            ),
            outflow AS (
                SELECT stock_movements.product_id, -SUM(stock_movements.quantity) AS quantity
                FROM stock_movements
                CROSS JOIN params
                WHERE stock_movements.type IN (?, ?)
                    AND stock_movements.created_at >= params.from_utc
                    AND stock_movements.product_id IS NOT NULL
                GROUP BY stock_movements.product_id
            )
            SELECT
                products.id AS product_id,
                products.name AS product_name,
                units.abbreviation AS unit_abbreviation,
                products.is_atomic,
                COALESCE(stock.usable, 0)::numeric(15, 4)::text AS usable_quantity,
                GREATEST(COALESCE(stock.usable, 0) - COALESCE(reserved.quantity, 0), 0)::numeric(15, 4)::text AS free_quantity,
                COALESCE(stock.expired, 0)::numeric(15, 4)::text AS expired_quantity,
                COALESCE(stock.expiring, 0)::numeric(15, 4)::text AS expiring_quantity,
                stock.next_expires_at::text AS next_expires_at,
                COALESCE(outflow.quantity, 0)::numeric(30, 4)::text AS outflow_quantity
            FROM products
            JOIN units ON units.id = products.unit_id
            LEFT JOIN stock ON stock.product_id = products.id
            LEFT JOIN reserved ON reserved.product_id = products.id
            LEFT JOIN outflow ON outflow.product_id = products.id
            ORDER BY products.name, products.id
        ', [
            $today,
            $expiring_until,
            $from_utc,
            DeliveryPackageStatusEnum::PENDING->value,
            ...array_map(fn(StockMovementTypeEnum $type) => $type->value, self::OUTFLOW_TYPES),
        ]);

        return collect($rows);
    }

    /**
     * @param Collection<int, stdClass> $products
     * @return array<string, int>
     */
    private function stock(Collection $products, string $today, string $expiring_until): array {
        $items = Item::query()
            ->where('quantity', '>', 0)
            ->selectRaw('COUNT(*) AS items_count')
            ->selectRaw('COUNT(*) FILTER (WHERE expires_at < ?) AS expired_items_count', [$today])
            ->selectRaw('COUNT(*) FILTER (WHERE expires_at BETWEEN ? AND ?) AS expiring_items_count', [$today, $expiring_until])
            ->toBase()
            ->first();

        return [
            'products_count' => $products->count(),
            'products_in_stock' => $products->filter(fn(stdClass $row) => bccomp($row->usable_quantity, '0', DECIMAL_SCALE) > 0)->count(),
            'out_of_stock_count' => $products->filter(fn(stdClass $row) => $this->hasOutflow($row) && bccomp($row->free_quantity, '0', DECIMAL_SCALE) <= 0)->count(),
            'items_count' => (int)$items->items_count,
            'expired_items_count' => (int)$items->expired_items_count,
            'expiring_items_count' => (int)$items->expiring_items_count,
        ];
    }

    /**
     * Pacotes pendentes (e quantos têm item vencido desde a reserva: a entrega vai falhar), entregues no
     * período e no período anterior de mesmo tamanho (tendência).
     *
     * @return array<string, int|string|null>
     */
    private function deliveries(string $today, Carbon $from_utc, Carbon $previous_from_utc): array {
        $pending = DeliveryPackageStatusEnum::PENDING->value;
        $delivered = DeliveryPackageStatusEnum::DELIVERED->value;

        $packages = DeliveryPackage::query()
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS pending_count', [$pending])
            ->selectRaw('MIN(created_at) FILTER (WHERE status = ?) AS oldest_pending_at', [$pending])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ? AND delivered_at >= ?) AS delivered_count', [$delivered, $from_utc])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ? AND delivered_at >= ? AND delivered_at < ?) AS delivered_previous_count', [$delivered, $previous_from_utc, $from_utc])
            ->toBase()
            ->first();

        $with_expired_items = DB::table('delivery_packages')
            ->join('delivery_package_has_item', 'delivery_package_has_item.delivery_package_id', '=', 'delivery_packages.id')
            ->join('items', 'items.id', '=', 'delivery_package_has_item.item_id')
            ->where('delivery_packages.status', $pending)
            ->where('items.expires_at', '<', $today)
            ->distinct()
            ->count('delivery_packages.id');

        return [
            'pending_count' => (int)$packages->pending_count,
            'pending_with_expired_items_count' => $with_expired_items,
            'oldest_pending_at' => $packages->oldest_pending_at === null ? null : Carbon::parse($packages->oldest_pending_at, 'UTC')->toISOString(),
            'delivered_count' => (int)$packages->delivered_count,
            'delivered_previous_count' => (int)$packages->delivered_previous_count,
        ];
    }

    /**
     * Baixas do período: total (e do período anterior), por motivo (`null` = baixa sem motivo) e os
     * produtos que mais perderam.
     *
     * @return array<string, mixed>
     */
    private function losses(Carbon $from_utc, Carbon $previous_from_utc): array {
        $write_off = StockMovementTypeEnum::WRITE_OFF->value;

        $totals = StockMovement::query()
            ->where('type', $write_off)
            ->where('created_at', '>=', $previous_from_utc)
            ->selectRaw('COUNT(*) FILTER (WHERE created_at >= ?) AS movements_count', [$from_utc])
            ->selectRaw('COUNT(*) FILTER (WHERE created_at < ?) AS previous_movements_count', [$from_utc])
            ->toBase()
            ->first();

        $by_reason = StockMovement::query()
            ->where('type', $write_off)
            ->where('created_at', '>=', $from_utc)
            ->groupBy('reason')
            ->select('reason')
            ->selectRaw('COUNT(*) AS movements_count')
            ->orderByDesc('movements_count')
            ->orderBy('reason')
            ->toBase()
            ->get()
            ->map(fn(object $row) => ['reason' => $row->reason, 'movements_count' => (int)$row->movements_count])
            ->all();

        $top_products = StockMovement::query()
            ->where('type', $write_off)
            ->where('created_at', '>=', $from_utc)
            ->groupBy(['product_id', 'product_name', 'unit_abbreviation'])
            ->select(['product_id', 'product_name', 'unit_abbreviation'])
            ->selectRaw('(-SUM(quantity))::numeric(30, 4)::text AS quantity')
            ->selectRaw('COUNT(*) AS movements_count')
            ->selectRaw('COUNT(*) FILTER (WHERE reason = ?) AS expired_count', [WriteOffReasonEnum::EXPIRED->value])
            ->orderByDesc('movements_count')
            ->orderBy('product_name')
            ->limit(self::LIST_LIMIT)
            ->toBase()
            ->get()
            ->map(fn(object $row) => [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'unit_abbreviation' => $row->unit_abbreviation,
                'quantity' => $row->quantity,
                'movements_count' => (int)$row->movements_count,
                'expired_count' => (int)$row->expired_count,
            ])
            ->all();

        return [
            'movements_count' => (int)$totals->movements_count,
            'previous_movements_count' => (int)$totals->previous_movements_count,
            'by_reason' => $by_reason,
            'top_products' => $top_products,
        ];
    }

    /**
     * Movimentações por dia do período (todos os dias, inclusive os sem nada): entradas
     * (entrada + produção), saídas (entrega + consumo) e baixas.
     *
     * @return array<int, array{date: string, inflow: int, outflow: int, losses: int}>
     */
    private function activity(string $from, string $today, Carbon $from_utc): array {
        $in = array_map(fn(StockMovementTypeEnum $type) => $type->value, self::INFLOW_TYPES);
        $out = array_map(fn(StockMovementTypeEnum $type) => $type->value, self::OUTFLOW_TYPES);

        $rows = StockMovement::query()
            ->where('created_at', '>=', $from_utc)
            ->selectRaw('((created_at AT TIME ZONE ?) AT TIME ZONE ?)::date::text AS day', [config('app.timezone'), config('app.business_timezone')])
            ->selectRaw('COUNT(*) FILTER (WHERE type IN (?, ?)) AS inflow', $in)
            ->selectRaw('COUNT(*) FILTER (WHERE type IN (?, ?)) AS outflow', $out)
            ->selectRaw('COUNT(*) FILTER (WHERE type = ?) AS losses', [StockMovementTypeEnum::WRITE_OFF->value])
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy('day');

        $days = [];

        for ($date = Carbon::parse($from); $date->toDateString() <= $today; $date->addDay()) {
            $row = $rows->get($date->toDateString());

            $days[] = [
                'date' => $date->toDateString(),
                'inflow' => (int)($row->inflow ?? 0),
                'outflow' => (int)($row->outflow ?? 0),
                'losses' => (int)($row->losses ?? 0),
            ];
        }

        return $days;
    }

    /**
     * Produtos mais entregues no período, por número de pacotes (quantidades de produtos diferentes não
     * se comparam).
     *
     * @return array<int, array<string, mixed>>
     */
    private function topDelivered(Carbon $from_utc): array {
        return StockMovement::query()
            ->where('type', StockMovementTypeEnum::DELIVERY->value)
            ->where('created_at', '>=', $from_utc)
            ->groupBy(['product_id', 'product_name', 'unit_abbreviation'])
            ->select(['product_id', 'product_name', 'unit_abbreviation'])
            ->selectRaw('(-SUM(quantity))::numeric(30, 4)::text AS quantity')
            ->selectRaw('COUNT(DISTINCT delivery_package_id) AS packages_count')
            ->orderByDesc('packages_count')
            ->orderByRaw('-SUM(quantity) DESC')
            ->orderBy('product_name')
            ->limit(self::LIST_LIMIT)
            ->toBase()
            ->get()
            ->map(fn(object $row) => [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'unit_abbreviation' => $row->unit_abbreviation,
                'quantity' => $row->quantity,
                'packages_count' => (int)$row->packages_count,
            ])
            ->all();
    }

    /**
     * Reposição: produtos com saída no período, do que acaba primeiro para o que acaba depois.
     * `daily_outflow` = saída do período ÷ dias; `coverage_days` = estoque livre ÷ saída diária
     * (quantos dias o livre aguenta no ritmo do período; `0` = já acabou).
     *
     * @param Collection<int, stdClass> $products
     * @return array<int, array<string, mixed>>
     */
    private function restock(Collection $products, int $days): array {
        $rows = [];

        foreach ($products as $row) {
            if (!$this->hasOutflow($row)) {
                continue;
            }

            $rows[] = [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'unit_abbreviation' => $row->unit_abbreviation,
                'free_quantity' => $row->free_quantity,
                'outflow_quantity' => $row->outflow_quantity,
                'daily_outflow' => (string)bcdiv($row->outflow_quantity, (string)$days, DECIMAL_SCALE),
                'coverage_days' => (string)bcdiv(bcmul($row->free_quantity, (string)$days, DECIMAL_SCALE), $row->outflow_quantity, DECIMAL_SCALE),
            ];
        }

        usort($rows, fn(array $a, array $b) => bccomp($a['coverage_days'], $b['coverage_days'], DECIMAL_SCALE)
            ?: bccomp($b['daily_outflow'], $a['daily_outflow'], DECIMAL_SCALE));

        return array_slice($rows, 0, self::LIST_LIMIT);
    }

    /**
     * Produtos com estoque vencido (para dar baixa) ou vencendo em até `Item::EXPIRING_DAYS` dias (para
     * sair primeiro): vencidos antes, depois pelo vencimento mais próximo.
     *
     * @param Collection<int, stdClass> $products
     * @return array<int, array<string, mixed>>
     */
    private function expirationAlerts(Collection $products): array {
        $rows = [];

        foreach ($products as $row) {
            $has_expired = bccomp($row->expired_quantity, '0', DECIMAL_SCALE) > 0;

            if (!$has_expired && bccomp($row->expiring_quantity, '0', DECIMAL_SCALE) <= 0) {
                continue;
            }

            $rows[] = [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'unit_abbreviation' => $row->unit_abbreviation,
                'expired_quantity' => $row->expired_quantity,
                'expiring_quantity' => $row->expiring_quantity,
                'next_expires_at' => $row->next_expires_at,
            ];
        }

        usort($rows, fn(array $a, array $b) => (bccomp($b['expired_quantity'], '0', DECIMAL_SCALE) > 0) <=> (bccomp($a['expired_quantity'], '0', DECIMAL_SCALE) > 0)
            ?: ($a['next_expires_at'] ?? '9999-12-31') <=> ($b['next_expires_at'] ?? '9999-12-31'));

        return array_slice($rows, 0, self::LIST_LIMIT);
    }

    private function hasOutflow(stdClass $row): bool {
        return bccomp($row->outflow_quantity, '0', DECIMAL_SCALE) > 0;
    }
}
