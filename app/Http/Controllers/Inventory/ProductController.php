<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\Product\CreateProductAction;
use App\Actions\Inventory\Product\DeleteProductAction;
use App\Actions\Inventory\Product\UpdateProductAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Common\ListPageDTO;
use App\Data\Inventory\Product\SaveProductDTO;
use App\Http\Requests\Product\SaveProductRequest;
use App\Http\Resources\Inventory\ProductResource;
use App\Queries\Inventory\ListProductsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ProductController extends BaseController {

    /**
     * @param Request $request
     * @param ListProductsQuery $query
     * @return JsonResponse|Response
     */
    public function index(Request $request, ListProductsQuery $query): JsonResponse|Response {
        try {
            $dto = ListPageDTO::fromRequest($request);
            $paginator = $query->get($dto);

            return self::successResponse(
                data: self::paginated($paginator, ProductResource::class)
            );
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.products.fetch_error');
        }
    }

    /**
     * @param SaveProductRequest $request
     * @param CreateProductAction $action
     * @return JsonResponse|Response
     */
    public function store(SaveProductRequest $request, CreateProductAction $action): JsonResponse|Response {
        try {
            return self::successResponse(
                data: ProductResource::make($action->handle(SaveProductDTO::fromRequest($request))),
                message: 'messages.products.created',
                status_code: 201
            );
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.products.create_error');
        }
    }

    /**
     * Substitui todos os dados do produto (inclusive categorias e receita).
     *
     * @param SaveProductRequest $request
     * @param UpdateProductAction $action
     * @param string $product_id
     * @return JsonResponse|Response
     */
    public function update(SaveProductRequest $request, UpdateProductAction $action, string $product_id): JsonResponse|Response {
        try {
            return self::successResponse(
                data: ProductResource::make($action->handle($product_id, SaveProductDTO::fromRequest($request))),
                message: 'messages.products.updated'
            );
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.products.update_error');
        }
    }

    /**
     * @param DeleteProductAction $action
     * @param string $product_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteProductAction $action, string $product_id): JsonResponse|Response {
        try {
            $action->handle($product_id);

            return self::successResponse(message: 'messages.products.deleted');
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.products.delete_error');
        }
    }
}
