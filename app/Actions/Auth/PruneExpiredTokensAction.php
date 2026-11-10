<?php

namespace App\Actions\Auth;

use App\Models\Auth\AccessToken;
use App\Models\Auth\RefreshToken;

final class PruneExpiredTokensAction {

    /**
     * Apaga tokens vencidos (rodado todo dia pelo scheduler: `auth:prune-tokens`).
     * Refresh revogado **e ainda não vencido** fica: é ele que detecta o reuso de uma cópia antiga
     * e revoga a família. Depois de vencido, reapresentá-lo já dá 401 sem precisar da linha.
     *
     * @return array{access_tokens: int, refresh_tokens: int} quantos foram apagados
     */
    public function handle(): array {
        $now = now();

        return [
            'access_tokens' => AccessToken::query()->where('expires_at', '<=', $now)->delete(),
            'refresh_tokens' => RefreshToken::query()->where('expires_at', '<=', $now)->delete(),
        ];
    }
}
