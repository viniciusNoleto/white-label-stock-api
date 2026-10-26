<?php

namespace App\Http\Controllers\Inventory;

use App\Base\Http\Controllers\BaseController;
use App\DTO\Inventory\Storage\SaveStorageDTO;
use App\Http\Requests\Storage\SaveStorageRequest;
use App\Http\Resources\Inventory\StorageResource;
use App\Services\Inventory\Storage\CreateStorageService;
use App\Services\Inventory\Storage\DeleteStorageService;
use App\Services\Inventory\Storage\ListStoragesService;
use App\Services\Inventory\Storage\UpdateStorageService;
use Exception;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class StorageController extends BaseController {

    /**
     * @param ListStoragesService $service
     * @return JsonResponse|Response
     */
    public function index(ListStoragesService $service) {
        try {
            return self::successResponse(
                data: StorageResource::collection($service->execute())
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.storages.fetch_error');
        }
    }

    /**
     * @param SaveStorageRequest $request
     * @param CreateStorageService $service
     * @return JsonResponse|Response
     */
    public function store(SaveStorageRequest $request, CreateStorageService $service) {
        try {
            return self::successResponse(
                data: StorageResource::make($service->execute(SaveStorageDTO::fromRequest($request))),
                message: 'messages.storages.created',
                status_code: 201
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.storages.create_error');
        }
    }

    /**
     * @param SaveStorageRequest $request
     * @param UpdateStorageService $service
     * @param string $storage_id
     * @return JsonResponse|Response
     */
    public function update(SaveStorageRequest $request, UpdateStorageService $service, string $storage_id) {
        try {
            return self::successResponse(
                data: StorageResource::make($service->execute($storage_id, SaveStorageDTO::fromRequest($request))),
                message: 'messages.storages.updated'
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.storages.update_error');
        }
    }

    /**
     * @param DeleteStorageService $service
     * @param string $storage_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteStorageService $service, string $storage_id) {
        try {
            $service->execute($storage_id);

            return self::successResponse(message: 'messages.storages.deleted');
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.storages.delete_error');
        }
    }
}
