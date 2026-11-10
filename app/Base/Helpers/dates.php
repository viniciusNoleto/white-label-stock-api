<?php

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
