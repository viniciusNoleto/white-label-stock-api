<?php

namespace App\Http\Middleware;

use App\Base\Traits\Response;
use App\Queries\Auth\FindUserByAccessTokenQuery;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Protege as rotas da API: lê o access token do header `Authorization: Bearer`. Sem token
 * válido responde 401 — o web entende o 401 como "renove a sessão via refresh_token e repita".
 */
class AuthenticateMiddleware {

    use Response;

    public function __construct(
        private readonly FindUserByAccessTokenQuery $findUserByAccessToken
    ) {
    }

    public function handle(Request $request, Closure $next): mixed {
        try {
            $user = $this->findUserByAccessToken->get($request->bearerToken());

            if (!$user) {
                return self::notAuthorizeExceptionResponse();
            }

            Auth::setUser($user);
            // Junto com o `request_id`, todo log (e alerta) da requisição diz de quem ela veio.
            Context::add('user_id', $user->id);

            return $next($request);
        } catch (Throwable $exception) {
            return self::returnError($exception);
        }
    }
}
