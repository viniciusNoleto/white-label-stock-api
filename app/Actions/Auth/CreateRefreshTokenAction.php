<?php

namespace App\Actions\Auth;

use App\Data\Auth\IssuedTokenDTO;
use App\Models\Auth\RefreshToken;
use Illuminate\Support\Str;

final class CreateRefreshTokenAction {

    /**
     * Sem `family_id` abre uma família nova (login); na rotação, mantém a família do token anterior.
     *
     * @param int $user_id
     * @param string|null $family_id
     * @return IssuedTokenDTO
     */
    public function handle(int $user_id, ?string $family_id = null): IssuedTokenDTO {
        $token = generate_opaque_token();
        $expires_at = now()->addDays(config('auth_tokens.refresh_token_ttl_days'));
        $family_id ??= (string)Str::uuid();

        RefreshToken::create([
            'user_id' => $user_id,
            'token_hash' => hash_token($token),
            'family_id' => $family_id,
            'expires_at' => $expires_at,
        ]);

        return new IssuedTokenDTO(token: $token, expires_at: $expires_at, family_id: $family_id);
    }
}
