<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\Unit\CreateUnitAction;
use App\Actions\Inventory\Unit\DeleteUnitAction;
use App\Actions\Inventory\Unit\UpdateUnitAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Common\ListPageDTO;
use App\Data\Inventory\Unit\SaveUnitDTO;
use App\Http\Requests\Unit\SaveUnitRequest;
use App\Http\Resources\Inventory\UnitResource;
use App\Models\Inventory\Unit;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UnitController extends BaseController {

    /**
     * Paginada (`page`, `per_page`), por nome.
     *
     * @param Request $request
     * @return JsonResponse|Response
     */
    public function index(Request $request) {
        try {
            $dto = ListPageDTO::fromRequest($request);
            $paginator = Unit::query()
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(perPage: $dto->per_page, page: $dto->page);

            return self::successResponse(
                data: [
                    'items' => UnitResource::collection($paginator->items()),
                    'meta' => [
                        'page' => $dto->page,
                        'per_page' => $dto->per_page,
                        'total' => $paginator->total(),
                        'last_page' => max(1, $paginator->lastPage()),
                    ],
                ]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.units.fetch_error');
        }
    }

    /**
     * @param SaveUnitRequest $request
     * @param CreateUnitAction $action
     * @return JsonResponse|Response
     */
    public function store(SaveUnitRequest $request, CreateUnitAction $action) {
        try {
            return self::successResponse(
                data: UnitResource::make($action->handle(SaveUnitDTO::fromRequest($request))),
                message: 'messages.units.created',
                status_code: 201
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.units.create_error');
        }
    }

    /**
     * @param SaveUnitRequest $request
     * @param UpdateUnitAction $action
     * @param string $unit_id
     * @return JsonResponse|Response
     */
    public function update(SaveUnitRequest $request, UpdateUnitAction $action, string $unit_id) {
        try {
            return self::successResponse(
                data: UnitResource::make($action->handle($unit_id, SaveUnitDTO::fromRequest($request))),
                message: 'messages.units.updated'
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.units.update_error');
        }
    }

    /**
     * @param DeleteUnitAction $action
     * @param string $unit_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteUnitAction $action, string $unit_id) {
        try {
            $action->handle($unit_id);

            return self::successResponse(message: 'messages.units.deleted');
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.units.delete_error');
        }
    }
}
