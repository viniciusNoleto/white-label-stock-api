<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlação de logs: reaproveita o `X-Request-Id` recebido (o web/proxy pode gerar) ou cria um,
 * coloca no `Context` (o Laravel anexa a todo log da requisição) e devolve no header da resposta.
 * Com o id que o usuário vê no erro, dá para achar a linha do log.
 */
class AssignRequestIdMiddleware {

    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response {
        $incoming = $request->headers->get(self::HEADER);
        $request_id = is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $incoming) ? $incoming : (string)Str::uuid();

        Context::add('request_id', $request_id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $request_id);

        return $response;
    }
}
