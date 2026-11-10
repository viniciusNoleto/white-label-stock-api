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

if (!function_exists('is_unique_violation')) {
    /**
     * SQLSTATE 23505: valor duplicado num índice único.
     *
     * @param Throwable $exception
     * @return bool
     */
    function is_unique_violation(Throwable $exception): bool {
        return $exception instanceof QueryException && (string)$exception->getCode() === '23505';
    }
}

if (!function_exists('is_concurrency_failure')) {
    /**
     * Conflito entre transações simultâneas: 40P01 (deadlock), 40001 (serialização) ou 55P03 (lock
     * não disponível). As Actions de estoque já repetem a transação (`DB::transaction(…, attempts)`);
     * se mesmo assim falhar, a resposta é 409 "tente de novo", não 500.
     *
     * @param Throwable $exception
     * @return bool
     */
    function is_concurrency_failure(Throwable $exception): bool {
        return $exception instanceof QueryException && in_array((string)$exception->getCode(), ['40P01', '40001', '55P03'], true);
    }
}

if (!function_exists('escape_like')) {
    /**
     * Escapa os curingas do LIKE/ILIKE (`%`, `_` e a própria barra, o escape padrão do Postgres): a busca
     * por `50%` procura o texto "50%", não "50 seguido de qualquer coisa".
     *
     * @param string $value
     * @return string
     */
    function escape_like(string $value): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
