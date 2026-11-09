<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\Item\CreateItemsAction;
use App\Actions\Inventory\Item\DeleteItemAction;
use App\Actions\Inventory\Item\UpdateItemAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Inventory\Item\CreateItemsDTO;
use App\Data\Inventory\Item\ListItemsDTO;
use App\Data\Inventory\Item\UpdateItemDTO;
use App\Http\Requests\Item\CreateItemsRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\Inventory\ItemResource;
use App\Queries\Inventory\ListItemsQuery;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ItemController extends BaseController {

    /**
     * @param Request $request
     * @param ListItemsQuery $query
     * @return JsonResponse|Response
     */
    public function index(Request $request, ListItemsQuery $query) {
        try {
            $dto = ListItemsDTO::fromRequest($request);
            $paginator = $query->get($dto);

            return self::successResponse(
                data: [
                    'items' => ItemResource::collection($paginator->items()),
                    'meta' => [
                        'page' => $dto->page,
                        'per_page' => $dto->per_page,
                        'total' => $paginator->total(),
                        'last_page' => max(1, $paginator->lastPage()),
                    ],
                ]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.items.fetch_error');
        }
    }

    /**
     * Gera itens de um produto (um ou um lote), opcionalmente consumindo a receita.
     *
     * @param CreateItemsRequest $request
     * @param CreateItemsAction $action
     * @return JsonResponse|Response
     */
    public function store(CreateItemsRequest $request, CreateItemsAction $action) {
        try {
            $items = $action->handle(CreateItemsDTO::fromRequest($request));

            return self::successResponse(
                data: ['items' => ItemResource::collection($items)],
                message: 'messages.items.created',
                status_code: 201,
                replace: ['count' => $items->count()]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.items.create_error');
        }
    }

    /**
     * @param UpdateItemRequest $request
     * @param UpdateItemAction $action
     * @param string $item_id
     * @return JsonResponse|Response
     */
    public function update(UpdateItemRequest $request, UpdateItemAction $action, string $item_id) {
        try {
            $dto = UpdateItemDTO::fromRequest($request);

            return self::successResponse(
                data: ItemResource::make($action->handle($item_id, $dto)),
                message: $dto->changesOnlyQuantity() ? 'messages.items.quantity_updated' : 'messages.items.updated'
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.items.update_error');
        }
    }

    /**
     * @param DeleteItemAction $action
     * @param string $item_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteItemAction $action, string $item_id) {
        try {
            $action->handle($item_id);

            return self::successResponse(message: 'messages.items.deleted');
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.items.delete_error');
        }
    }
}
