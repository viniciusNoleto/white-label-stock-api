<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\Storage\CreateStorageAction;
use App\Actions\Inventory\Storage\DeleteStorageAction;
use App\Actions\Inventory\Storage\UpdateStorageAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Common\ListPageDTO;
use App\Data\Inventory\Storage\SaveStorageDTO;
use App\Http\Requests\Storage\SaveStorageRequest;
use App\Http\Resources\Inventory\StorageResource;
use App\Models\Inventory\Storage;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StorageController extends BaseController {

    /**
     * Paginada (`page`, `per_page`), por nome.
     *
     * @param Request $request
     * @return JsonResponse|Response
     */
    public function index(Request $request) {
        try {
            $dto = ListPageDTO::fromRequest($request);
            $paginator = Storage::query()
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(perPage: $dto->per_page, page: $dto->page);

            return self::successResponse(
                data: [
                    'items' => StorageResource::collection($paginator->items()),
                    'meta' => [
                        'page' => $dto->page,
                        'per_page' => $dto->per_page,
                        'total' => $paginator->total(),
                        'last_page' => max(1, $paginator->lastPage()),
                    ],
                ]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.storages.fetch_error');
        }
    }

    /**
     * @param SaveStorageRequest $request
     * @param CreateStorageAction $action
     * @return JsonResponse|Response
     */
    public function store(SaveStorageRequest $request, CreateStorageAction $action) {
        try {
            return self::successResponse(
                data: StorageResource::make($action->handle(SaveStorageDTO::fromRequest($request))),
                message: 'messages.storages.created',
                status_code: 201
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.storages.create_error');
        }
    }

    /**
     * @param SaveStorageRequest $request
     * @param UpdateStorageAction $action
     * @param string $storage_id
     * @return JsonResponse|Response
     */
    public function update(SaveStorageRequest $request, UpdateStorageAction $action, string $storage_id) {
        try {
            return self::successResponse(
                data: StorageResource::make($action->handle($storage_id, SaveStorageDTO::fromRequest($request))),
                message: 'messages.storages.updated'
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.storages.update_error');
        }
    }

    /**
     * @param DeleteStorageAction $action
     * @param string $storage_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteStorageAction $action, string $storage_id) {
        try {
            $action->handle($storage_id);

            return self::successResponse(message: 'messages.storages.deleted');
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.storages.delete_error');
        }
    }
}
