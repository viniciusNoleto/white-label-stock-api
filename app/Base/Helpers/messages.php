<?php

use App\Enum\Locale\ApiLocaleEnum;

if (!function_exists('api_message')) {
    /**
     * Mensagem da API em todos os idiomas: `{ "pt-br": "…", "es-mx": "…", "en-us": "…" }`.
     *
     * @param string $key chave de `lang/{locale}/messages.php` (ex.: `messages.units.created`)
     * @param array<string, string|int|float> $replace
     * @return array<string, string>
     */
    function api_message(string $key, array $replace = []): array {
        $message = [];

        foreach (ApiLocaleEnum::cases() as $locale) {
            $message[$locale->value] = __($key, $replace, $locale->laravelLocale());
        }

        return $message;
    }
}

if (!function_exists('is_message_key')) {
    /**
     * @param string|null $value
     * @return bool
     */
    function is_message_key(?string $value): bool {
        return is_string($value) && str_starts_with($value, 'messages.');
    }
}
