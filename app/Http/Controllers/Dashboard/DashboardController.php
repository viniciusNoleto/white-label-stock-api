<?php

namespace App\Http\Controllers\Dashboard;

use App\Base\Http\Controllers\BaseController;
use App\Data\Dashboard\ShowDashboardDTO;
use App\Http\Resources\Dashboard\DashboardResource;
use App\Queries\Dashboard\GetDashboardQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DashboardController extends BaseController {

    /**
     * Estatísticas do negócio para a página inicial (`?period=7|30|90`, padrão 30). Sem rate limit:
     * a proteção do banco é o cache versionado (`DashboardCache`).
     *
     * @param Request $request
     * @param GetDashboardQuery $query
     * @return JsonResponse|Response
     */
    public function show(Request $request, GetDashboardQuery $query): JsonResponse|Response {
        try {
            return self::successResponse(
                data: DashboardResource::make($query->get(ShowDashboardDTO::fromRequest($request)))
            );
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.dashboard.fetch_error');
        }
    }
}
