<?php

namespace App\Services\Auth;

use App\Repositories\Auth\AccessTokenRepository;
use App\Repositories\Auth\RefreshTokenRepository;

class LogoutService {

    public function __construct(
        private readonly AccessTokenRepository  $accessTokenRepository,
        private readonly RefreshTokenRepository $refreshTokenRepository
    ) {
    }

    /**
     * @param string|null $access_token
     * @param string|null $refresh_token
     * @return void
     */
    public function execute(?string $access_token, ?string $refresh_token): void {
        if ($access_token) {
            $this->accessTokenRepository->deleteByToken($access_token);
        }

        if ($refresh_token) {
            $this->refreshTokenRepository->deleteByToken($refresh_token);
        }
    }
}
