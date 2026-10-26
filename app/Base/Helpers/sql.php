<?php

use Illuminate\Database\QueryException;

if (!function_exists('is_foreign_key_violation')) {
    /**
     * SQLSTATE 23503: registro referenciado por outro (ao excluir) ou referência inexistente (ao gravar).
     *
     * @param Throwable $exception
     * @return bool
     */
    function is_foreign_key_violation(Throwable $exception): bool {
        return $exception instanceof QueryException && (string)$exception->getCode() === '23503';
    }
}
