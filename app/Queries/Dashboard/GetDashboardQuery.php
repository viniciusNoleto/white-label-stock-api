<?php

namespace App\Queries\Dashboard;

use App\Base\Cache\DashboardCache;
use App\Data\Dashboard\ShowDashboardDTO;

/**
 * Painel com cache: calcula com `DashboardStatsQuery` só quando a entrada guardada não vale mais
 * (regras e proteção contra avalanche em `DashboardCache`).
 */
final class GetDashboardQuery {

    public function __construct(
        private readonly DashboardStatsQuery $stats
    ) {
    }

    /**
     * @param ShowDashboardDTO $dto
     * @return array<string, mixed>
     */
    public function get(ShowDashboardDTO $dto): array {
        return DashboardCache::remember(
            'dashboard:' . $dto->period->value,
            fn() => $this->stats->get($dto->period)
        );
    }
}
