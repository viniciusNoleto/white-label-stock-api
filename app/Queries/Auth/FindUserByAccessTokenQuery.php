<?php

namespace App\Queries\Auth;

use App\Models\Auth\AccessToken;
use App\Models\User;

final class FindUserByAccessTokenQuery {

    /**
     * Usuário dono de um access token válido (existente e não expirado).
     *
     * @param string|null $access_token
     * @return User|null
     */
    public function get(?string $access_token): ?User {
        if (!$access_token) {
            return null;
        }

        return AccessToken::query()->byToken($access_token)->notExpired()->with('user')->first()?->user;
    }
}
