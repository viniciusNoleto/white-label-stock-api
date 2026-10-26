<?php

namespace App\Services\Auth;

use App\DTO\Auth\IssuedTokenDTO;
use App\Repositories\Auth\AccessTokenRepository;

class CreateAccessTokenService {

    public function __construct(
        private readonly AccessTokenRepository $accessTokenRepository
    ) {
    }

    /**
     * @param int $user_id
     * @return IssuedTokenDTO
     */
    public function execute(int $user_id): IssuedTokenDTO {
        $token = generate_opaque_token();
        $expires_at = now()->addMinutes(config('auth_tokens.access_token_ttl_minutes'));

        $this->accessTokenRepository->save([
            'user_id' => $user_id,
            'token_hash' => hash_token($token),
            'expires_at' => $expires_at,
        ]);

        return new IssuedTokenDTO(token: $token, expires_at: $expires_at);
    }
}
