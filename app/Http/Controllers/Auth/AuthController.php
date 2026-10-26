<?php

namespace App\Http\Controllers\Auth;

use App\Base\Http\Controllers\BaseController;
use App\Base\Traits\AuthCookies;
use App\DTO\Auth\LoginDTO;
use App\Enum\Auth\AuthCookieEnum;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\AuthUserResource;
use App\Services\Auth\FindUserByAccessTokenService;
use App\Services\Auth\LoginService;
use App\Services\Auth\LogoutService;
use App\Services\Auth\RefreshSessionService;
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
     * @param LoginService $service
     * @return JsonResponse|Response
     */
    public function login(LoginRequest $request, LoginService $service) {
        try {
            $session = $service->execute(LoginDTO::fromRequest($request));

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
     * @param LogoutService $service
     * @return JsonResponse|Response
     */
    public function logout(Request $request, LogoutService $service) {
        try {
            $service->execute(
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
     * @param FindUserByAccessTokenService $service
     * @return JsonResponse|Response
     */
    public function me(Request $request, FindUserByAccessTokenService $service) {
        try {
            $user = $service->execute($request->cookie(AuthCookieEnum::ACCESS_TOKEN->value));

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
     * @param RefreshSessionService $service
     * @return JsonResponse|Response
     */
    public function refreshToken(Request $request, RefreshSessionService $service) {
        try {
            $refresh_token = $request->cookie(AuthCookieEnum::REFRESH_TOKEN->value);
            $session = $refresh_token ? $service->execute($refresh_token) : null;

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
