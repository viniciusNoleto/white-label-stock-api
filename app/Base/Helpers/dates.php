<?php

use Illuminate\Support\Carbon;

if (!function_exists('parse_date')) {
    /**
     * Data `Y-m-d` válida vinda da query string/payload; `null` para qualquer outra coisa.
     *
     * @param mixed $value
     * @return string|null
     */
    function parse_date(mixed $value): ?string {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
            return null;
        }

        return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) ? $value : null;
    }
}

if (!function_exists('business_now')) {
    /**
     * Agora no fuso do negócio (`app.business_timezone`). Os timestamps continuam gravados em UTC;
     * este fuso só decide qual é o dia de "hoje" nas regras do estoque.
     *
     * @return Carbon
     */
    function business_now(): Carbon {
        return now()->setTimezone(config('app.business_timezone'));
    }
}

if (!function_exists('business_today')) {
    /**
     * Dia de hoje (`Y-m-d`) no fuso do negócio: a referência de "vencido" (`expires_at < hoje`),
     * da data de produção padrão e da janela "vencendo". Às 22h em São Paulo ainda é hoje,
     * mesmo que em UTC já seja amanhã.
     *
     * @return string
     */
    function business_today(): string {
        return business_now()->toDateString();
    }
}

if (!function_exists('business_day_start_utc')) {
    /**
     * Início (00:00) do dia `Y-m-d` no fuso do negócio, convertido para o fuso de gravação (UTC):
     * para filtrar timestamps por dia inteiro do jeito que o usuário vê o calendário.
     *
     * @param string $date
     * @return Carbon
     */
    function business_day_start_utc(string $date): Carbon {
        return Carbon::parse($date, config('app.business_timezone'))->startOfDay()->setTimezone(config('app.timezone'));
    }
}
