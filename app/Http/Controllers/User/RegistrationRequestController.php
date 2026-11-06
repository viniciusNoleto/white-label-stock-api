<?php

namespace App\Http\Controllers\User;

use App\Actions\Auth\RegistrationRequest\ApproveRegistrationRequestAction;
use App\Actions\Auth\RegistrationRequest\RejectRegistrationRequestAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Common\ListPageDTO;
use App\Http\Resources\User\RegistrationRequestResource;
use App\Http\Resources\User\UserResource;
use App\Models\Auth\RegistrationRequest;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pedidos de cadastro (`POST /api/auth/register`) aguardando aprovação. Só admins (middleware `admin`).
 */
class RegistrationRequestController extends BaseController {

    /**
     * Mais antigos primeiro (fila de atendimento).
     *
     * @param Request $request
     * @return JsonResponse|Response
     */
    public function index(Request $request) {
        try {
            $dto = ListPageDTO::fromRequest($request);
            $paginator = RegistrationRequest::query()
                ->search($dto->search)
                ->orderBy('created_at')
                ->orderBy('id')
                ->paginate(perPage: $dto->per_page, page: $dto->page);

            return self::successResponse(
                data: [
                    'items' => RegistrationRequestResource::collection($paginator->items()),
                    'meta' => [
                        'page' => $dto->page,
                        'per_page' => $dto->per_page,
                        'total' => $paginator->total(),
                        'last_page' => max(1, $paginator->lastPage()),
                    ],
                ]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.registration_requests.fetch_error');
        }
    }

    /**
     * @param ApproveRegistrationRequestAction $action
     * @param string $registration_request_id
     * @return JsonResponse|Response
     */
    public function approve(ApproveRegistrationRequestAction $action, string $registration_request_id) {
        try {
            return self::successResponse(
                data: UserResource::make($action->handle($registration_request_id)),
                message: 'messages.registration_requests.approved',
                status_code: 201
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.registration_requests.approve_error');
        }
    }

    /**
     * Recusar = excluir o pedido.
     *
     * @param RejectRegistrationRequestAction $action
     * @param string $registration_request_id
     * @return JsonResponse|Response
     */
    public function destroy(RejectRegistrationRequestAction $action, string $registration_request_id) {
        try {
            $action->handle($registration_request_id);

            return self::successResponse(message: 'messages.registration_requests.rejected');
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.registration_requests.reject_error');
        }
    }
}
