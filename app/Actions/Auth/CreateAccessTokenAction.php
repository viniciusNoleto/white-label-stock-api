<?php

namespace App\Actions\Auth;

use App\Data\Auth\IssuedTokenDTO;
use App\Models\Auth\AccessToken;

final class CreateAccessTokenAction {

    /**
     * @param int $user_id
     * @return IssuedTokenDTO
     */
    public function handle(int $user_id): IssuedTokenDTO {
        $token = generate_opaque_token();
        $expires_at = now()->addMinutes(config('auth_tokens.access_token_ttl_minutes'));

        AccessToken::create([
            'user_id' => $user_id,
            'token_hash' => hash_token($token),
            'expires_at' => $expires_at,
        ]);

        return new IssuedTokenDTO(token: $token, expires_at: $expires_at);
    }
}
