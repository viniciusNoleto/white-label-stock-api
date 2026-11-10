<?php

namespace App\Data\Dashboard;

use App\Enum\Dashboard\DashboardPeriodEnum;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class ShowDashboardDTO extends Data {

    /**
     * @param DashboardPeriodEnum $period janela das estatísticas de movimentação (o retrato do estoque é sempre de agora)
     */
    public function __construct(
        public DashboardPeriodEnum $period,
    ) {
    }

    /**
     * Como nos filtros das listagens, período inválido é ignorado: vale o padrão (30 dias).
     *
     * @param Request $request
     * @return ShowDashboardDTO
     */
    public static function fromRequest(Request $request): ShowDashboardDTO {
        $period = $request->query('period');

        return new self(
            period: (is_string($period) && ctype_digit($period) ? DashboardPeriodEnum::tryFrom((int)$period) : null) ?? DashboardPeriodEnum::MONTH,
        );
    }
}
