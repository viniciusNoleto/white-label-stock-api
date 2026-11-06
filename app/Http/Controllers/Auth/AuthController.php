<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginAction;
use App\Actions\Auth\LogoutAction;
use App\Actions\Auth\RefreshSessionAction;
use App\Actions\Auth\RegistrationRequest\CreateRegistrationRequestAction;
use App\Base\Http\Controllers\BaseController;
use App\Data\Auth\LoginDTO;
use App\Data\Auth\RegisterDTO;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\LogoutRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\AuthUserResource;
use App\Http\Resources\Auth\SessionResource;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login e refresh devolvem os tokens no corpo (`SessionResource`); as rotas protegidas
 * recebem o access token no header `Authorization: Bearer`.
 */
class AuthController extends BaseController {

    /**
     * @param LoginRequest $request
     * @param LoginAction $action
     * @return JsonResponse|Response
     */
    public function login(LoginRequest $request, LoginAction $action) {
        try {
            return self::successResponse(
                data: SessionResource::make($action->handle(LoginDTO::fromRequest($request))),
                message: 'messages.auth.logged_in'
            );
        } catch (Exception $exception) {
            return self::returnError($exception);
        }
    }

    /**
     * Cadastro público: não cria a conta, só o pedido — um admin aprova (vira usuário) ou recusa.
     *
     * @param RegisterRequest $request
     * @param CreateRegistrationRequestAction $action
     * @return JsonResponse|Response
     */
    public function register(RegisterRequest $request, CreateRegistrationRequestAction $action) {
        try {
            $action->handle(RegisterDTO::fromRequest($request));

            return self::successResponse(message: 'messages.registration_requests.created', status_code: 201);
        } catch (Exception $exception) {
            return self::returnError($exception, 'messages.registration_requests.create_error');
        }
    }

    /**
     * Fora do middleware `auth`: com o access já expirado, ainda revoga o refresh_token.
     *
     * @param LogoutRequest $request
     * @param LogoutAction $action
     * @return JsonResponse|Response
     */
    public function logout(LogoutRequest $request, LogoutAction $action) {
        try {
            $action->handle(
                access_token: $request->bearerToken(),
                refresh_token: $request->validated('refresh_token')
            );

            return self::successResponse(message: 'messages.auth.logged_out');
        } catch (Exception $exception) {
            return self::returnError($exception);
        }
    }

    /**
     * Rota protegida: sem access token válido o middleware responde 401 (o web renova e repete).
     *
     * @param Request $request
     * @return JsonResponse|Response
     */
    public function me(Request $request) {
        try {
            return self::successResponse(data: ['user' => AuthUserResource::make($request->user())]);
        } catch (Exception $exception) {
            return self::returnError($exception);
        }
    }

    /**
     * Rotaciona o refresh_token e emite um access token novo.
     *
     * @param RefreshTokenRequest $request
     * @param RefreshSessionAction $action
     * @return JsonResponse|Response
     */
    public function refreshToken(RefreshTokenRequest $request, RefreshSessionAction $action) {
        try {
            $session = $action->handle($request->validated('refresh_token'));

            if (!$session) {
                return self::errorResponse('messages.unauthorized', 401);
            }

            return self::successResponse(data: SessionResource::make($session));
        } catch (Exception $exception) {
            return self::returnError($exception);
        }
    }
}
