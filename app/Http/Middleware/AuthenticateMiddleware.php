<?php

namespace App\Http\Middleware;

use App\Base\Traits\Response;
use App\Enum\Auth\AuthCookieEnum;
use App\Queries\Auth\FindUserByAccessTokenQuery;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Protege as rotas da API. Sem access token válido responde 401 — o web entende o 401
 * como "renove a sessão via refresh_token e repita".
 */
class AuthenticateMiddleware {

    use Response;

    public function __construct(
        private readonly FindUserByAccessTokenQuery $findUserByAccessToken
    ) {
    }

    public function handle(Request $request, Closure $next): mixed {
        try {
            $user = $this->findUserByAccessToken->get(
                $request->cookie(AuthCookieEnum::ACCESS_TOKEN->value)
            );

            if (!$user) {
                return self::notAuthorizeExceptionResponse();
            }

            Auth::setUser($user);

            return $next($request);
        } catch (Exception $exception) {
            return self::returnError($exception);
        }
    }
}
