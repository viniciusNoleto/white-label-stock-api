<?php

namespace App\Exceptions;

use Exception;

/**
 * Erro de regra de negócio com mensagem traduzida (chave de `lang/{locale}/messages.php`).
 * O `Response::returnError` transforma em `{ success: false, message, data: null }`.
 */
class ApiException extends Exception {

    /**
     * @param string $message_key
     * @param int $status_code
     * @param array<string, string|int|float> $replace
     */
    public function __construct(
        public readonly string $message_key,
        public readonly int    $status_code = 422,
        public readonly array  $replace = [],
    ) {
        parent::__construct($message_key, $status_code);
    }
}
