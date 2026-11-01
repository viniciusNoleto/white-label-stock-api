<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginAction;
use App\Actions\Auth\LogoutAction;
use App\Actions\Auth\RefreshSessionAction;
use App\Base\Http\Controllers\BaseController;
use App\Base\Traits\AuthCookies;
use App\Data\Auth\LoginDTO;
use App\Enum\Auth\AuthCookieEnum;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\AuthUserResource;
use App\Queries\Auth\FindUserByAccessTokenQuery;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Os tokens nunca vão no corpo: só em cookies httpOnly (ver `AuthCookies`).
 */
class AuthController extends BaseController {

    use AuthCookies;

    /**
     * @param LoginRequest $request
     * @param LoginAction $action
     * @return JsonResponse|Response
     */
    public function login(LoginRequest $request, LoginAction $action) {
        try {
            $session = $action->handle(LoginDTO::fromRequest($request));

            return self::withAuthCookies(
                self::successResponse(
                    data: ['user' => AuthUserResource::make($session->user)],
                    message: 'messages.auth.logged_in'
                ),
                $session
            );
        } catch (Exception $exception) {
            return self::returnError($exception);
        }
    }

    /**
     * @param Request $request
     * @param LogoutAction $action
     * @return JsonResponse|Response
     */
    public function logout(Request $request, LogoutAction $action) {
        try {
            $action->handle(
                access_token: $request->cookie(AuthCookieEnum::ACCESS_TOKEN->value),
                refresh_token: $request->cookie(AuthCookieEnum::REFRESH_TOKEN->value)
            );

            return self::withoutAuthCookies(
                self::successResponse(message: 'messages.auth.logged_out')
            );
        } catch (Exception $exception) {
            return self::returnError($exception);
        }
    }

    /**
     * Access expirado mas ainda há refresh_token: o 401 faz o web renovar a sessão e repetir
     * a chamada. Sem nenhum cookie é só um visitante anônimo — sucesso com `user: null`.
     *
     * @param Request $request
     * @param FindUserByAccessTokenQuery $query
     * @return JsonResponse|Response
     */
    public function me(Request $request, FindUserByAccessTokenQuery $query) {
        try {
            $user = $query->get($request->cookie(AuthCookieEnum::ACCESS_TOKEN->value));

            if ($user) {
                return self::successResponse(data: ['user' => AuthUserResource::make($user)]);
            }

            if ($request->cookie(AuthCookieEnum::REFRESH_TOKEN->value)) {
                return self::errorResponse('messages.unauthorized', 401);
            }

            return self::successResponse(data: ['user' => null]);
        } catch (Exception $exception) {
            return self::returnError($exception);
        }
    }

    /**
     * Rotaciona o refresh_token e emite um access token novo. Sem refresh válido, apaga os cookies.
     *
     * @param Request $request
     * @param RefreshSessionAction $action
     * @return JsonResponse|Response
     */
    public function refreshToken(Request $request, RefreshSessionAction $action) {
        try {
            $refresh_token = $request->cookie(AuthCookieEnum::REFRESH_TOKEN->value);
            $session = $refresh_token ? $action->handle($refresh_token) : null;

            if (!$session) {
                return self::withoutAuthCookies(self::errorResponse('messages.unauthorized', 401));
            }

            return self::withAuthCookies(
                self::successResponse(data: ['user' => AuthUserResource::make($session->user)]),
                $session
            );
        } catch (Exception $exception) {
            return self::returnError($exception);
        }
    }
}
