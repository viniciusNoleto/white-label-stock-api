<?php

use App\Base\Traits\Response as ResponseTrait;
use App\Http\Middleware\AuthenticateMiddleware;
use App\Http\Middleware\ForceJsonMiddleware;
use App\Http\Middleware\RejectMalformedJsonMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth' => AuthenticateMiddleware::class,
        ]);

        // Os cookies de sessão são opacos e httpOnly: não passam pela criptografia de cookies do Laravel.
        $middleware->encryptCookies(except: ['access_token', 'refresh_token']);

        // Atrás do nginx (e do proxy do web): respeita X-Forwarded-For/Proto.
        $middleware->trustProxies(at: '*');

        $middleware->prepend(ForceJsonMiddleware::class);
        $middleware->api(prepend: [RejectMalformedJsonMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $throwable) {
            return ResponseTrait::returnError($throwable);
        });
    })->create();
