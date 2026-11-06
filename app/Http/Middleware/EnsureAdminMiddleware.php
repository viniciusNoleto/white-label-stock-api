<?php

namespace App\Http\Middleware;

use App\Base\Traits\Response;
use Closure;
use Illuminate\Http\Request;

/**
 * Rotas de administração (usuários e pedidos de cadastro): só depois do `auth`. Usuário comum → 403.
 */
class EnsureAdminMiddleware {

    use Response;

    public function handle(Request $request, Closure $next): mixed {
        if (!$request->user()?->is_admin) {
            return self::errorResponse('messages.forbidden', 403);
        }

        return $next($request);
    }
}
