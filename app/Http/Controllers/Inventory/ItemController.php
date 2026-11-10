<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\Item\CreateItemsAction;
use App\Actions\Inventory\Item\DeleteItemAction;
use App\Actions\Inventory\Item\UpdateItemAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Inventory\Item\CreateItemsDTO;
use App\Data\Inventory\Item\ListItemsDTO;
use App\Data\Inventory\Item\UpdateItemDTO;
use App\Enum\Inventory\WriteOffReasonEnum;
use App\Http\Requests\Item\CreateItemsRequest;
use App\Http\Requests\Item\DeleteItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\Inventory\ItemResource;
use App\Queries\Inventory\ListItemsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ItemController extends BaseController {

    /**
     * @param Request $request
     * @param ListItemsQuery $query
     * @return JsonResponse|Response
     */
    public function index(Request $request, ListItemsQuery $query): JsonResponse|Response {
        try {
            $dto = ListItemsDTO::fromRequest($request);
            $paginator = $query->get($dto);

            return self::successResponse(
                data: self::paginated($paginator, ItemResource::class)
            );
        } catch (Throwable $exception) {
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
    public function store(CreateItemsRequest $request, CreateItemsAction $action): JsonResponse|Response {
        try {
            $items = $action->handle(CreateItemsDTO::fromRequest($request));

            return self::successResponse(
                data: ['items' => ItemResource::collection($items)],
                message: 'messages.items.created',
                status_code: 201,
                replace: ['count' => $items->count()]
            );
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.items.create_error');
        }
    }

    /**
     * @param UpdateItemRequest $request
     * @param UpdateItemAction $action
     * @param string $item_id
     * @return JsonResponse|Response
     */
    public function update(UpdateItemRequest $request, UpdateItemAction $action, string $item_id): JsonResponse|Response {
        try {
            $dto = UpdateItemDTO::fromRequest($request);

            return self::successResponse(
                data: ItemResource::make($action->handle($item_id, $dto)),
                message: $dto->changesOnlyQuantity() ? 'messages.items.quantity_updated' : 'messages.items.updated'
            );
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.items.update_error');
        }
    }

    /**
     * Baixa do item, com motivo opcional no corpo (`{ "reason": "expired" }`).
     *
     * @param DeleteItemRequest $request
     * @param DeleteItemAction $action
     * @param string $item_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteItemRequest $request, DeleteItemAction $action, string $item_id): JsonResponse|Response {
        try {
            $reason = $request->validated('reason');

            $action->handle($item_id, $reason === null ? null : WriteOffReasonEnum::from($reason));

            return self::successResponse(message: 'messages.items.deleted');
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.items.delete_error');
        }
    }
}
