<?php

namespace App\Repositories\Auth;

use App\Base\Repository\BaseRepository;
use App\Models\Auth\AccessToken;
use App\Models\User;

class AccessTokenRepository extends BaseRepository {

    public function __construct() {
        $this->setModel(AccessToken::class);
    }

    /**
     * @param string $token
     * @return User|null
     */
    public function findUserByValidToken(string $token): ?User {
        return $this->model->byToken($token)->notExpired()->with('user')->first()?->user;
    }

    /**
     * @param string $token
     * @return void
     */
    public function deleteByToken(string $token): void {
        $this->model->byToken($token)->delete();
    }
}
