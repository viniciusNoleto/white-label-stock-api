<?php

namespace App\Base\Cache;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cache do painel com versão: o painel agrega o estoque inteiro e é aberto o tempo todo, então o
 * resultado é guardado e só recalculado quando pode ter mudado.
 *
 * Uma entrada vale enquanto (1) a versão é a mesma, (2) o dia do negócio é o mesmo e (3) tem menos de
 * `dashboard.max_age_seconds`. A versão é trocada por `invalidate()`, chamado por toda escrita de
 * estoque (`RecordStockMovementsAction`), de pacote e de produto, **depois do commit** (antes, um
 * painel recalculado no meio da transação leria o estado antigo e o guardaria como novo).
 *
 * Contra a avalanche (várias abas abrindo o painel logo depois de uma mudança), só um processo
 * reconstrói por vez (lock de cache). Quem chega enquanto isso:
 * - tem uma entrada do mesmo dia → recebe essa (um pouco desatualizada) na hora, sem esperar;
 * - não tem nada → espera a reconstrução até `build_wait_seconds` e lê o resultado; se o lock não
 *   sair, calcula por conta própria. Ninguém recebe 409/429: o acesso nunca é negado.
 */
final class DashboardCache {

    public const VERSION_KEY = 'dashboard:version';

    /**
     * Troca a versão depois do commit da transação atual (na hora, se não houver transação).
     */
    public static function invalidate(): void {
        DB::afterCommit(fn() => Cache::forever(self::VERSION_KEY, (string)Str::ulid()));
    }

    /**
     * @param string $key uma por variante do painel (ex.: `dashboard:30`)
     * @param Closure(): array<string, mixed> $build calcula o painel a partir do banco
     * @return array<string, mixed>
     */
    public static function remember(string $key, Closure $build): array {
        $today = business_today();
        $entry = Cache::get($key);

        if (self::isFresh($entry, $today)) {
            return $entry['data'];
        }

        $lock = Cache::lock($key . ':build', config('dashboard.build_lock_seconds'));

        if (!$lock->get()) {
            if (self::isSameDay($entry, $today)) {
                return $entry['data'];
            }

            if (!self::waitFor($lock)) {
                return $build();
            }
        }

        try {
            // Quem segurava o lock pode ter acabado de guardar uma entrada válida.
            $entry = Cache::get($key);

            if (self::isFresh($entry, $today)) {
                return $entry['data'];
            }

            // Versão lida **antes** de calcular: uma escrita durante o cálculo deixa a entrada velha de cara.
            $version = self::version();
            $data = $build();

            Cache::put($key, [
                'version' => $version,
                'today' => $today,
                'built_at' => now()->getTimestamp(),
                'data' => $data,
            ], config('dashboard.store_seconds'));

            return $data;
        } finally {
            $lock->release();
        }
    }

    /**
     * Tenta pegar o lock até `build_wait_seconds`. O prazo é medido no relógio monotônico (`hrtime`), não
     * em `now()`: o `Lock::block()` do framework usa `now()`, que não anda com o relógio fixo dos testes
     * (laço infinito) e pula com ajustes do relógio do sistema.
     *
     * @param Lock $lock
     * @return bool `true` com o lock na mão
     */
    private static function waitFor(Lock $lock): bool {
        $deadline = hrtime(true) + (int)(config('dashboard.build_wait_seconds') * 1_000_000_000);

        do {
            usleep(100_000);

            if ($lock->get()) {
                return true;
            }
        } while (hrtime(true) < $deadline);

        return false;
    }

    private static function version(): string {
        return (string)Cache::get(self::VERSION_KEY, 'initial');
    }

    /**
     * @param mixed $entry
     * @param string $today
     * @return bool
     */
    private static function isFresh(mixed $entry, string $today): bool {
        return self::isSameDay($entry, $today)
            && $entry['version'] === self::version()
            && now()->getTimestamp() - $entry['built_at'] < config('dashboard.max_age_seconds');
    }

    /**
     * @param mixed $entry
     * @param string $today
     * @return bool
     */
    private static function isSameDay(mixed $entry, string $today): bool {
        return is_array($entry) && ($entry['today'] ?? null) === $today && array_key_exists('data', $entry);
    }
}
