<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\InventoryItem\BuildInventoryItemAction;
use App\Actions\Inventory\InventoryItem\CreateInventoryItemAction;
use App\Actions\Inventory\InventoryItem\UpdateInventoryItemAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Inventory\InventoryItem\BuildInventoryItemDTO;
use App\Data\Inventory\InventoryItem\CreateInventoryItemDTO;
use App\Data\Inventory\InventoryItem\ListInventoryItemsDTO;
use App\Data\Inventory\InventoryItem\UpdateInventoryItemDTO;
use App\Http\Requests\InventoryItem\BuildInventoryItemRequest;
use App\Http\Requests\InventoryItem\CreateInventoryItemRequest;
use App\Http\Requests\InventoryItem\UpdateInventoryItemRequest;
use App\Http\Resources\Inventory\InventoryItemListResource;
use App\Http\Resources\Inventory\InventoryItemResource;
use App\Queries\Inventory\ListInventoryItemsQuery;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class InventoryItemController extends BaseController {

    /**
     * @param Request $request
     * @param ListInventoryItemsQuery $query
     * @return JsonResponse|Response
     */
    public function index(Request $request, ListInventoryItemsQuery $query) {
        try {
            $dto = ListInventoryItemsDTO::fromRequest($request);
            $paginator = $query->get($dto);

            return self::successResponse(
                data: [
                    'items' => InventoryItemListResource::collection($paginator->items()),
                    'meta' => [
                        'page' => $dto->page,
                        'per_page' => $dto->per_page,
                        'total' => $paginator->total(),
                        'last_page' => max(1, $paginator->lastPage()),
                    ],
                ]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.inventory_items.fetch_error');
        }
    }

    /**
     * @param CreateInventoryItemRequest $request
     * @param CreateInventoryItemAction $action
     * @return JsonResponse|Response
     * @throws Throwable
     */
    public function store(CreateInventoryItemRequest $request, CreateInventoryItemAction $action) {
        try {
            return self::successResponse(
                data: InventoryItemResource::make($action->handle(CreateInventoryItemDTO::fromRequest($request))),
                message: 'messages.inventory_items.created',
                status_code: 201
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.inventory_items.create_error');
        }
    }

    /**
     * Atualização parcial: quantidade (absoluta, soma ou subtração), nome e/ou armazenamento.
     *
     * @param UpdateInventoryItemRequest $request
     * @param UpdateInventoryItemAction $action
     * @param string $inventory_item_id
     * @return JsonResponse|Response
     */
    public function update(UpdateInventoryItemRequest $request, UpdateInventoryItemAction $action, string $inventory_item_id) {
        try {
            $dto = UpdateInventoryItemDTO::fromRequest($request);

            return self::successResponse(
                data: InventoryItemResource::make($action->handle($inventory_item_id, $dto)),
                message: $this->updateMessage($dto)
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.inventory_items.update_error');
        }
    }

    /**
     * @param BuildInventoryItemRequest $request
     * @param BuildInventoryItemAction $action
     * @param string $inventory_item_id
     * @return JsonResponse|Response
     * @throws Throwable
     */
    public function build(BuildInventoryItemRequest $request, BuildInventoryItemAction $action, string $inventory_item_id) {
        try {
            $dto = BuildInventoryItemDTO::fromRequest($request);

            return self::successResponse(
                data: InventoryItemResource::make($action->handle($inventory_item_id, $dto)),
                message: 'messages.inventory_items.built',
                replace: ['quantity' => format_decimal($dto->quantity)]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.inventory_items.build_error');
        }
    }

    /**
     * Mensagem de sucesso conforme o que foi alterado.
     *
     * @param UpdateInventoryItemDTO $dto
     * @return string
     */
    private function updateMessage(UpdateInventoryItemDTO $dto): string {
        return match (true) {
            $dto->changesName() => 'messages.inventory_items.name_updated',
            $dto->has_storage_id => 'messages.inventory_items.storage_updated',
            default => 'messages.inventory_items.quantity_updated',
        };
    }
}
