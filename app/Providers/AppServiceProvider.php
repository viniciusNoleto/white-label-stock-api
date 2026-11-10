<?php

namespace App\Providers;

use App\Base\Database\ConcurrencyErrorDetector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {

    /**
     * Register any application services.
     */
    public function register(): void {
        // Retry de transação por SQLSTATE (deadlock/serialização), não pelo texto da mensagem.
        $this->app->singleton(ConcurrencyErrorDetectorContract::class, ConcurrencyErrorDetector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {
        $this->configureRateLimiting();

        // N+1 vira exceção fora de produção: relação usada sem `with()`/`load()` quebra o teste que passa
        // por ela. Em produção só seria uma consulta a mais, então não derruba a requisição.
        Model::preventLazyLoading(!$this->app->isProduction());

        // `/up` só responde 200 se o banco responder: o healthcheck do container e o monitoramento
        // passam a enxergar "php no ar, Postgres fora" (antes o `/up` não tocava o banco).
        Event::listen(DiagnosingHealth::class, fn() => DB::select('SELECT 1'));
    }

    /**
     * Rotas públicas de autenticação (429 no envelope de sempre, com `Retry-After`).
     * O IP é o do cliente: o web repassa `X-Forwarded-For` e a API só confia nesse header vindo de
     * rede privada (`TRUSTED_PROXIES`, ver `bootstrap/app.php`).
     */
    private function configureRateLimiting(): void {
        // Força bruta numa conta (por e-mail + IP) e varredura de várias contas (por IP).
        RateLimiter::for('login', fn(Request $request) => [
            Limit::perMinute(5)->by('login:' . mb_strtolower((string)$request->input('email')) . '|' . $request->ip()),
            Limit::perMinute(20)->by('login-ip:' . $request->ip()),
        ]);

        // Cadastro público: protege a fila de aprovação do admin e a enumeração pelo 409.
        RateLimiter::for('register', fn(Request $request) => [
            Limit::perMinute(3)->by('register:' . $request->ip()),
            Limit::perHour(10)->by('register-hour:' . $request->ip()),
        ]);

        // Troca de senha: com um access token roubado, limita as tentativas de adivinhar a senha atual
        // (por sessão, pelo hash do token; e por IP, contra quem troca de token).
        RateLimiter::for('password', fn(Request $request) => [
            Limit::perMinute(5)->by('password:' . hash_token((string)$request->bearerToken())),
            Limit::perMinute(20)->by('password-ip:' . $request->ip()),
        ]);

        // O SSR do web renova a sessão direto do servidor (todos os usuários saem do mesmo IP), então o
        // limite fino é por token; o por IP é só um teto contra abuso.
        RateLimiter::for('refresh', fn(Request $request) => [
            Limit::perMinute(10)->by('refresh:' . hash_token((string)$request->input('refresh_token'))),
            Limit::perMinute(300)->by('refresh-ip:' . $request->ip()),
        ]);
    }
}
