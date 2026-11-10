<?php

use App\Data\Dashboard\ShowDashboardDTO;
use App\Enum\Dashboard\DashboardPeriodEnum;
use App\Models\Inventory\Product;
use App\Queries\Dashboard\GetDashboardQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('rebuilds the dashboard once when many requests find the cache empty at the same time, with the production cache store', function () {
    // Como em produção: lock e entrada no cache `database`, compartilhado entre processos.
    config(['cache.default' => 'database']);
    Product::factory()->count(3)->create();

    $results = runConcurrently(8, function () {
        // A consulta por produto (`WITH params AS …`) só roda quando o painel é calculado.
        $builds = 0;
        DB::listen(function (QueryExecuted $query) use (&$builds) {
            if (str_contains($query->sql, 'WITH params AS')) {
                $builds++;
            }
        });

        $dashboard = app(GetDashboardQuery::class)->get(new ShowDashboardDTO(DashboardPeriodEnum::MONTH));

        return ['builds' => $builds, 'products_count' => $dashboard['stock']['products_count']];
    });

    expect(collect($results)->where('ok', false)->all())->toBe([])
        ->and(collect($results)->sum('value.builds'))->toBe(1)
        ->and(collect($results)->pluck('value.products_count')->unique()->all())->toBe([3]);
});
