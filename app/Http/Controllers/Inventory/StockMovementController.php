<?php

namespace App\Http\Controllers\Inventory;

use App\Base\Http\Controllers\BaseController;
use App\Data\Inventory\StockMovement\ListStockMovementsDTO;
use App\Http\Resources\Inventory\StockMovementResource;
use App\Http\Resources\Inventory\StockMovementSummaryResource;
use App\Queries\Inventory\ListStockMovementsQuery;
use App\Queries\Inventory\SummarizeStockMovementsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StockMovementController extends BaseController {

    /**
     * Histórico das movimentações dos itens (só leitura: são gravadas pelas próprias operações).
     *
     * @param Request $request
     * @param ListStockMovementsQuery $query
     * @return JsonResponse|Response
     */
    public function index(Request $request, ListStockMovementsQuery $query): JsonResponse|Response {
        try {
            $dto = ListStockMovementsDTO::fromRequest($request);
            $paginator = $query->get($dto);

            return self::successResponse(
                data: self::paginated($paginator, StockMovementResource::class)
            );
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.stock_movements.fetch_error');
        }
    }

    /**
     * Totais por produto, tipo e motivo, com os mesmos filtros da listagem (ex.: perda por vencimento no mês).
     *
     * @param Request $request
     * @param SummarizeStockMovementsQuery $query
     * @return JsonResponse|Response
     */
    public function summary(Request $request, SummarizeStockMovementsQuery $query): JsonResponse|Response {
        try {
            $dto = ListStockMovementsDTO::fromRequest($request);

            return self::successResponse(
                data: ['items' => StockMovementSummaryResource::collection($query->get($dto))]
            );
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.stock_movements.summary_error');
        }
    }
}
