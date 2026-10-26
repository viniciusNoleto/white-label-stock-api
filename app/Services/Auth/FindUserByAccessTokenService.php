<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Repositories\Auth\AccessTokenRepository;

class FindUserByAccessTokenService {

    public function __construct(
        private readonly AccessTokenRepository $accessTokenRepository
    ) {
    }

    /**
     * @param string|null $access_token
     * @return User|null
     */
    public function execute(?string $access_token): ?User {
        if (!$access_token) {
            return null;
        }

        return $this->accessTokenRepository->findUserByValidToken($access_token);
    }
}
