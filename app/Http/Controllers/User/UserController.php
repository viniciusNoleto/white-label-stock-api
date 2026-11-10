<?php

namespace App\Http\Controllers\User;

use App\Actions\User\DeleteUserAction;
use App\Actions\User\ResetUserPasswordAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Common\ListPageDTO;
use App\Data\User\ResetUserPasswordDTO;
use App\Http\Requests\User\ResetUserPasswordRequest;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Só admins (middleware `admin`).
 */
class UserController extends BaseController {

    /**
     * Admins primeiro, depois por nome.
     *
     * @param Request $request
     * @return JsonResponse|Response
     */
    public function index(Request $request): JsonResponse|Response {
        try {
            $dto = ListPageDTO::fromRequest($request);
            $paginator = User::query()
                ->search($dto->search)
                ->orderByDesc('is_admin')
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(perPage: $dto->per_page, page: $dto->page);

            return self::successResponse(
                data: self::paginated($paginator, UserResource::class)
            );
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.users.fetch_error');
        }
    }

    /**
     * @param DeleteUserAction $action
     * @param string $user_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteUserAction $action, string $user_id): JsonResponse|Response {
        try {
            $action->handle($user_id);

            return self::successResponse(message: 'messages.users.deleted');
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.users.delete_error');
        }
    }

    /**
     * Define uma senha nova para um usuário comum (admins → 422) e encerra as sessões dele.
     *
     * @param ResetUserPasswordRequest $request
     * @param ResetUserPasswordAction $action
     * @param string $user_id
     * @return JsonResponse|Response
     */
    public function resetPassword(ResetUserPasswordRequest $request, ResetUserPasswordAction $action, string $user_id): JsonResponse|Response {
        try {
            $action->handle($user_id, ResetUserPasswordDTO::fromRequest($request));

            return self::successResponse(message: 'messages.users.password_reset');
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.users.password_reset_error');
        }
    }
}
