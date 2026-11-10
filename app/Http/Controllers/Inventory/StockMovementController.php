<?php

namespace App\Http\Controllers\Inventory;

use App\Base\Http\Controllers\BaseController;
use App\Data\Inventory\StockMovement\ListStockMovementsDTO;
use App\Http\Resources\Inventory\StockMovementResource;
use App\Queries\Inventory\ListStockMovementsQuery;
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
}
