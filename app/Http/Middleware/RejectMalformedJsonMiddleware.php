<?php

namespace App\Http\Middleware;

use App\Base\Traits\Response;
use Closure;
use Illuminate\Http\Request;

/**
 * O Laravel ignora em silêncio um corpo JSON inválido (vira payload vazio). Aqui ele é recusado com 400.
 */
class RejectMalformedJsonMiddleware {

    use Response;

    public function handle(Request $request, Closure $next): mixed {
        $content = $request->getContent();

        if ($request->isJson() && trim($content) !== '' && !json_validate($content)) {
            return self::errorResponse('messages.invalid_payload', 400);
        }

        return $next($request);
    }
}
