<?php

namespace App\Http\Controllers\Inventory;

use App\Base\Http\Controllers\BaseController;
use App\DTO\Inventory\ItemCategory\SaveItemCategoryDTO;
use App\Http\Requests\ItemCategory\SaveItemCategoryRequest;
use App\Http\Resources\Inventory\ItemCategoryResource;
use App\Services\Inventory\ItemCategory\CreateItemCategoryService;
use App\Services\Inventory\ItemCategory\DeleteItemCategoryService;
use App\Services\Inventory\ItemCategory\ListItemCategoriesService;
use App\Services\Inventory\ItemCategory\UpdateItemCategoryService;
use Exception;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ItemCategoryController extends BaseController {

    /**
     * @param ListItemCategoriesService $service
     * @return JsonResponse|Response
     */
    public function index(ListItemCategoriesService $service) {
        try {
            return self::successResponse(
                data: ItemCategoryResource::collection($service->execute())
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.item_categories.fetch_error');
        }
    }

    /**
     * @param SaveItemCategoryRequest $request
     * @param CreateItemCategoryService $service
     * @return JsonResponse|Response
     */
    public function store(SaveItemCategoryRequest $request, CreateItemCategoryService $service) {
        try {
            return self::successResponse(
                data: ItemCategoryResource::make($service->execute(SaveItemCategoryDTO::fromRequest($request))),
                message: 'messages.item_categories.created',
                status_code: 201
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.item_categories.create_error');
        }
    }

    /**
     * @param SaveItemCategoryRequest $request
     * @param UpdateItemCategoryService $service
     * @param string $item_category_id
     * @return JsonResponse|Response
     */
    public function update(SaveItemCategoryRequest $request, UpdateItemCategoryService $service, string $item_category_id) {
        try {
            return self::successResponse(
                data: ItemCategoryResource::make($service->execute($item_category_id, SaveItemCategoryDTO::fromRequest($request))),
                message: 'messages.item_categories.updated'
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.item_categories.update_error');
        }
    }

    /**
     * @param DeleteItemCategoryService $service
     * @param string $item_category_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteItemCategoryService $service, string $item_category_id) {
        try {
            $service->execute($item_category_id);

            return self::successResponse(message: 'messages.item_categories.deleted');
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.item_categories.delete_error');
        }
    }
}
