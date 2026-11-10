<?php

if (!defined('DECIMAL_SCALE')) {
    /**
     * Casas decimais das quantidades (`decimal(15, 4)`): a escala de **toda** conta com bcmath. Uma
     * constante só, em vez de `SCALE = 4` repetido em cada classe que faz conta.
     */
    define('DECIMAL_SCALE', 4);
}

if (!defined('DECIMAL_MAX')) {
    /** Maior valor que cabe numa coluna `decimal(15, 4)`: 11 dígitos inteiros e 4 casas. */
    define('DECIMAL_MAX', '99999999999.9999');
}

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

if (!function_exists('to_decimal')) {
    /**
     * Normaliza um decimal **já validado** (`decimal_rule()`) para string com `$scale` casas, sem passar
     * por `float` quando ele chega como string: `"2.5"` → `"2.5000"`, `3` → `"3.0000"`.
     * Número JSON (float) já perdeu precisão no `json_decode`; com no máximo 4 casas e 15 dígitos
     * (o que a validação garante), o `sprintf` devolve exatamente o valor digitado.
     *
     * @param int|float|string $value
     * @param int $scale
     * @return string
     */
    function to_decimal(int|float|string $value, int $scale = DECIMAL_SCALE): string {
        if (is_float($value)) {
            return sprintf('%.' . $scale . 'F', $value);
        }

        return bcadd(trim((string)$value), '0', $scale);
    }
}

if (!function_exists('decimal_rule')) {
    /**
     * Regra de validação dos decimais gravados em `decimal(15, 4)`: numérico, no máximo 4 casas
     * (`0.00001` não vira zero em silêncio) e no máximo 11 dígitos inteiros (`1e20` não estoura a
     * coluna com 500). Notação científica é recusada pelo `decimal`.
     *
     * @return string
     */
    function decimal_rule(): string {
        return 'numeric|decimal:0,4|max:' . DECIMAL_MAX;
    }
}

if (!function_exists('format_decimal')) {
    /**
     * Decimal sem zeros à direita, para mensagens: `"16.0000"` → `"16"`, `"2.5000"` → `"2.5"`.
     * Só bcmath/string, como o resto das contas: `(float)` arredondaria `"99999999999.9999"`.
     *
     * @param string|int $value decimal em string (ou inteiro)
     * @return string
     */
    function format_decimal(string|int $value): string {
        $formatted = bcadd(trim((string)$value), '0', DECIMAL_SCALE);

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}

if (!function_exists('is_whole_number')) {
    /**
     * Decimal (string) sem parte fracionária: `"3.0000"` → true, `"2.5000"` → false.
     *
     * @param string $value
     * @param int $scale
     * @return bool
     */
    function is_whole_number(string $value, int $scale = DECIMAL_SCALE): bool {
        return bccomp($value, bcadd($value, '0', 0), $scale) === 0;
    }
}
