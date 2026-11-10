<?php

namespace App\Http\Controllers\Inventory;

use App\Base\Http\Controllers\BaseController;
use App\Data\Inventory\Stock\ListStockDTO;
use App\Http\Resources\Inventory\StockRowResource;
use App\Queries\Inventory\ListStockQuery;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StockController extends BaseController {

    /**
     * Visão de operação: atômicos agrupados por produto, lotes a granel um por linha.
     *
     * @param Request $request
     * @param ListStockQuery $query
     * @return JsonResponse|Response
     */
    public function index(Request $request, ListStockQuery $query) {
        try {
            $dto = ListStockDTO::fromRequest($request);
            $paginator = $query->get($dto);

            return self::successResponse(
                data: [
                    'items' => StockRowResource::collection($paginator->items()),
                    'meta' => [
                        'page' => $dto->page,
                        'per_page' => $dto->per_page,
                        'total' => $paginator->total(),
                        'last_page' => max(1, $paginator->lastPage()),
                    ],
                ]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.stock.fetch_error');
        }
    }
}
