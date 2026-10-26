<?php

if (!function_exists('parse_id')) {
    /**
     * Converte um id vindo da rota/payload em inteiro positivo; `null` quando não é um id válido.
     *
     * @param mixed $value
     * @return int|null
     */
    function parse_id(mixed $value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && (int)$value > 0) {
            return (int)$value;
        }

        return null;
    }
}

if (!function_exists('format_decimal')) {
    /**
     * Decimal sem zeros à direita, para mensagens: `"16.0000"` → `"16"`, `"2.5000"` → `"2.5"`.
     *
     * @param string|int|float $value
     * @return string
     */
    function format_decimal(string|int|float $value): string {
        $formatted = number_format((float)$value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
