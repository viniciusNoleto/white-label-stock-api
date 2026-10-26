<?php

namespace App\Http\Controllers\Inventory;

use App\Base\Http\Controllers\BaseController;
use App\DTO\Inventory\InventoryItem\BuildInventoryItemDTO;
use App\DTO\Inventory\InventoryItem\CreateInventoryItemDTO;
use App\DTO\Inventory\InventoryItem\UpdateInventoryItemDTO;
use App\Http\Requests\InventoryItem\BuildInventoryItemRequest;
use App\Http\Requests\InventoryItem\CreateInventoryItemRequest;
use App\Http\Requests\InventoryItem\UpdateInventoryItemRequest;
use App\Http\Resources\Inventory\InventoryItemResource;
use App\Services\Inventory\InventoryItem\BuildInventoryItemService;
use App\Services\Inventory\InventoryItem\CreateInventoryItemService;
use App\Services\Inventory\InventoryItem\ListInventoryItemsService;
use App\Services\Inventory\InventoryItem\UpdateInventoryItemService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class InventoryItemController extends BaseController {

    /**
     * @param Request $request
     * @param ListInventoryItemsService $service
     * @return JsonResponse|Response
     */
    public function index(Request $request, ListInventoryItemsService $service) {
        try {
            return self::successResponse(
                data: $service->execute($request)
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.inventory_items.fetch_error');
        }
    }

    /**
     * @param CreateInventoryItemRequest $request
     * @param CreateInventoryItemService $service
     * @return JsonResponse|Response
     * @throws Throwable
     */
    public function store(CreateInventoryItemRequest $request, CreateInventoryItemService $service) {
        try {
            return self::successResponse(
                data: InventoryItemResource::make($service->execute(CreateInventoryItemDTO::fromRequest($request))),
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
     * @param UpdateInventoryItemService $service
     * @param string $inventory_item_id
     * @return JsonResponse|Response
     */
    public function update(UpdateInventoryItemRequest $request, UpdateInventoryItemService $service, string $inventory_item_id) {
        try {
            $dto = UpdateInventoryItemDTO::fromRequest($request);

            return self::successResponse(
                data: InventoryItemResource::make($service->execute($inventory_item_id, $dto)),
                message: $service->successMessage($dto)
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.inventory_items.update_error');
        }
    }

    /**
     * @param BuildInventoryItemRequest $request
     * @param BuildInventoryItemService $service
     * @param string $inventory_item_id
     * @return JsonResponse|Response
     * @throws Throwable
     */
    public function build(BuildInventoryItemRequest $request, BuildInventoryItemService $service, string $inventory_item_id) {
        try {
            $dto = BuildInventoryItemDTO::fromRequest($request);

            return self::successResponse(
                data: InventoryItemResource::make($service->execute($inventory_item_id, $dto)),
                message: 'messages.inventory_items.built',
                replace: ['quantity' => format_decimal($dto->quantity)]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.inventory_items.build_error');
        }
    }
}
