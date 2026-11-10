<?php

namespace App\Actions\Auth;

use App\Data\Auth\IssuedTokenDTO;
use App\Models\Auth\AccessToken;

final class CreateAccessTokenAction {

    /**
     * @param int $user_id
     * @param string|null $family_id família do refresh token emitido junto (a detecção de reuso derruba os dois)
     * @return IssuedTokenDTO
     */
    public function handle(int $user_id, ?string $family_id = null): IssuedTokenDTO {
        $token = generate_opaque_token();
        $expires_at = now()->addMinutes(config('auth_tokens.access_token_ttl_minutes'));

        AccessToken::create([
            'user_id' => $user_id,
            'token_hash' => hash_token($token),
            'family_id' => $family_id,
            'expires_at' => $expires_at,
        ]);

        return new IssuedTokenDTO(token: $token, expires_at: $expires_at, family_id: $family_id);
    }
}
