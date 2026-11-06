<?php

namespace App\Http\Controllers\User;

use App\Actions\User\DeleteUserAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Common\ListPageDTO;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
    public function index(Request $request) {
        try {
            $dto = ListPageDTO::fromRequest($request);
            $paginator = User::query()
                ->search($dto->search)
                ->orderByDesc('is_admin')
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(perPage: $dto->per_page, page: $dto->page);

            return self::successResponse(
                data: [
                    'items' => UserResource::collection($paginator->items()),
                    'meta' => [
                        'page' => $dto->page,
                        'per_page' => $dto->per_page,
                        'total' => $paginator->total(),
                        'last_page' => max(1, $paginator->lastPage()),
                    ],
                ]
            );
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.users.fetch_error');
        }
    }

    /**
     * @param DeleteUserAction $action
     * @param string $user_id
     * @return JsonResponse|Response
     */
    public function destroy(DeleteUserAction $action, string $user_id) {
        try {
            $action->handle($user_id);

            return self::successResponse(message: 'messages.users.deleted');
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.users.delete_error');
        }
    }
}
