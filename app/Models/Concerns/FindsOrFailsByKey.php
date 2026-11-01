<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException;

trait FindsOrFailsByKey {

    /**
     * Busca pelo id vindo da rota/payload; ids inválidos (`abc`, `-1`) também respondem "não encontrado".
     * A `message_key` vira a mensagem do 404 (`Response::modelNotFoundResponse`).
     *
     * @param mixed $id
     * @param string $message_key
     * @param array $with
     * @return static
     */
    public static function findOrFailByKey(
        mixed  $id,
        string $message_key = 'messages.not_found',
        array  $with = []
    ): static {
        $parsed_id = parse_id($id);
        $model = $parsed_id ? static::query()->with($with)->find($parsed_id) : null;

        if (!$model) {
            throw new ModelNotFoundException($message_key);
        }

        return $model;
    }
}
