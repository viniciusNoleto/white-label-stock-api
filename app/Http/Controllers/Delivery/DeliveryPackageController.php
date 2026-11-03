<?php

namespace App\Http\Controllers\Delivery;

use App\Actions\Delivery\DeliveryPackage\CreateDeliveryPackageAction;
use App\Actions\Delivery\DeliveryPackage\DeleteDeliveryPackageAction;
use App\Actions\Delivery\DeliveryPackage\DeliverDeliveryPackageAction;
use App\Actions\Delivery\DeliveryPackage\UpdateDeliveryPackageAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Delivery\DeliveryPackage\ListDeliveryPackagesDTO;
use App\Data\Delivery\DeliveryPackage\SaveDeliveryPackageDTO;
use App\Http\Requests\DeliveryPackage\SaveDeliveryPackageRequest;
use App\Http\Resources\Delivery\DeliveryPackageResource;
use App\Models\Delivery\DeliveryPackage;
use App\Queries\Delivery\ListDeliveryPackagesQuery;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeliveryPackageController extends BaseController {

    /**
     * @param Request $request
     * @param ListDeliveryPackagesQuery $query
     * @return JsonResponse|Response
     */
    public function index(Request $request, ListDeliveryPackagesQuery $query) {
        try {
            $dto = ListDeliveryPackagesDTO::fromRequest($request);
            $paginator = $query->get($dto);

            return self::successResponse(
                data: [
                    'items' => DeliveryPackageResource::collection($paginator->items()),
                    'meta' => [
                        'page' => $dto->page,
                        'per_page' => $dto->per_page,
                        'total' => $paginator->total(),
                        'last_page' => max(1, $paginator->lastPage()),
                    ],
                ]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.delivery_packages.fetch_error');
        }
    }

    /**
     * @param string $delivery_package_id
     * @return JsonResponse|Response
     */
    public function show(string $delivery_package_id) {
        try {
            return self::successResponse(
                data: DeliveryPackageResource::make(DeliveryPackage::findOrFailByKey(
                    $delivery_package_id,
                    'messages.delivery_packages.not_found',
                    ListDeliveryPackagesQuery::relations()
                ))
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.delivery_packages.fetch_error');
        }
    }

    /**
     * @param SaveDeliveryPackageRequest $request
     * @param CreateDeliveryPackageAction $action
     * @return JsonResponse|Response
     */
    public function store(SaveDeliveryPackageRequest $request, CreateDeliveryPackageAction $action) {
        try {
            return self::successResponse(
                data: DeliveryPackageResource::make($action->handle(SaveDeliveryPackageDTO::fromRequest($request))),
                message: 'messages.delivery_packages.created',
                status_code: 201
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.delivery_packages.create_error');
        }
    }

    /**
     * Substitui anotações e itens de um pacote pendente.
     *
     * @param SaveDeliveryPackageRequest $request
     * @param UpdateDeliveryPackageAction $action
     * @param string $delivery_package_id
     * @return JsonResponse|Response
     */
    public function update(SaveDeliveryPackageRequest $request, UpdateDeliveryPackageAction $action, string $delivery_package_id) {
        try {
            return self::successResponse(
                data: DeliveryPackageResource::make($action->handle($delivery_package_id, SaveDeliveryPackageDTO::fromRequest($request))),
                message: 'messages.delivery_packages.updated'
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.delivery_packages.update_error');
        }
    }

    /**
     * @param DeleteDeliveryPackageAction $action
     * @param string $delivery_package_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteDeliveryPackageAction $action, string $delivery_package_id) {
        try {
            $action->handle($delivery_package_id);

            return self::successResponse(message: 'messages.delivery_packages.deleted');
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.delivery_packages.delete_error');
        }
    }

    /**
     * Marca como entregue e subtrai os itens do estoque.
     *
     * @param DeliverDeliveryPackageAction $action
     * @param string $delivery_package_id
     * @return JsonResponse|Response
     */
    public function deliver(DeliverDeliveryPackageAction $action, string $delivery_package_id) {
        try {
            return self::successResponse(
                data: DeliveryPackageResource::make($action->handle($delivery_package_id)),
                message: 'messages.delivery_packages.delivered'
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.delivery_packages.deliver_error');
        }
    }
}
