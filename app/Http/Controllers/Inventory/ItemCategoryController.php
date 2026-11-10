<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\ItemCategory\CreateItemCategoryAction;
use App\Actions\Inventory\ItemCategory\DeleteItemCategoryAction;
use App\Actions\Inventory\ItemCategory\UpdateItemCategoryAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Common\ListPageDTO;
use App\Data\Inventory\ItemCategory\SaveItemCategoryDTO;
use App\Http\Requests\ItemCategory\SaveItemCategoryRequest;
use App\Http\Resources\Inventory\ItemCategoryResource;
use App\Models\Inventory\ItemCategory;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ItemCategoryController extends BaseController {

    /**
     * Paginada (`page`, `per_page`), por nome.
     *
     * @param Request $request
     * @return JsonResponse|Response
     */
    public function index(Request $request) {
        try {
            $dto = ListPageDTO::fromRequest($request);
            $paginator = ItemCategory::query()
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(perPage: $dto->per_page, page: $dto->page);

            return self::successResponse(
                data: [
                    'items' => ItemCategoryResource::collection($paginator->items()),
                    'meta' => [
                        'page' => $dto->page,
                        'per_page' => $dto->per_page,
                        'total' => $paginator->total(),
                        'last_page' => max(1, $paginator->lastPage()),
                    ],
                ]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.item_categories.fetch_error');
        }
    }

    /**
     * @param SaveItemCategoryRequest $request
     * @param CreateItemCategoryAction $action
     * @return JsonResponse|Response
     */
    public function store(SaveItemCategoryRequest $request, CreateItemCategoryAction $action) {
        try {
            return self::successResponse(
                data: ItemCategoryResource::make($action->handle(SaveItemCategoryDTO::fromRequest($request))),
                message: 'messages.item_categories.created',
                status_code: 201
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.item_categories.create_error');
        }
    }

    /**
     * @param SaveItemCategoryRequest $request
     * @param UpdateItemCategoryAction $action
     * @param string $item_category_id
     * @return JsonResponse|Response
     */
    public function update(SaveItemCategoryRequest $request, UpdateItemCategoryAction $action, string $item_category_id) {
        try {
            return self::successResponse(
                data: ItemCategoryResource::make($action->handle($item_category_id, SaveItemCategoryDTO::fromRequest($request))),
                message: 'messages.item_categories.updated'
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.item_categories.update_error');
        }
    }

    /**
     * @param DeleteItemCategoryAction $action
     * @param string $item_category_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteItemCategoryAction $action, string $item_category_id) {
        try {
            $action->handle($item_category_id);

            return self::successResponse(message: 'messages.item_categories.deleted');
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.item_categories.delete_error');
        }
    }
}
