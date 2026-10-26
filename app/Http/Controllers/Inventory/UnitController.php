<?php

namespace App\Http\Controllers\Inventory;

use App\Base\Http\Controllers\BaseController;
use App\DTO\Inventory\Unit\SaveUnitDTO;
use App\Http\Requests\Unit\SaveUnitRequest;
use App\Http\Resources\Inventory\UnitResource;
use App\Services\Inventory\Unit\CreateUnitService;
use App\Services\Inventory\Unit\DeleteUnitService;
use App\Services\Inventory\Unit\ListUnitsService;
use App\Services\Inventory\Unit\UpdateUnitService;
use Exception;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UnitController extends BaseController {

    /**
     * @param ListUnitsService $service
     * @return JsonResponse|Response
     */
    public function index(ListUnitsService $service) {
        try {
            return self::successResponse(
                data: UnitResource::collection($service->execute())
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.units.fetch_error');
        }
    }

    /**
     * @param SaveUnitRequest $request
     * @param CreateUnitService $service
     * @return JsonResponse|Response
     */
    public function store(SaveUnitRequest $request, CreateUnitService $service) {
        try {
            return self::successResponse(
                data: UnitResource::make($service->execute(SaveUnitDTO::fromRequest($request))),
                message: 'messages.units.created',
                status_code: 201
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.units.create_error');
        }
    }

    /**
     * @param SaveUnitRequest $request
     * @param UpdateUnitService $service
     * @param string $unit_id
     * @return JsonResponse|Response
     */
    public function update(SaveUnitRequest $request, UpdateUnitService $service, string $unit_id) {
        try {
            return self::successResponse(
                data: UnitResource::make($service->execute($unit_id, SaveUnitDTO::fromRequest($request))),
                message: 'messages.units.updated'
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.units.update_error');
        }
    }

    /**
     * @param DeleteUnitService $service
     * @param string $unit_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteUnitService $service, string $unit_id) {
        try {
            $service->execute($unit_id);

            return self::successResponse(message: 'messages.units.deleted');
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.units.delete_error');
        }
    }
}
