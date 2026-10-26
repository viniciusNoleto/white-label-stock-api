<?php

namespace App\Repositories\Auth;

use App\Base\Repository\BaseRepository;
use App\Models\User;

class UserRepository extends BaseRepository {

    public function __construct() {
        $this->setModel(User::class);
    }

    /**
     * @param string $email
     * @return User|null
     */
    public function findByEmail(string $email): ?User {
        return $this->model->where('email', $email)->first();
    }

    /**
     * @param int $id
     * @return User|null
     */
    public function findById(int $id): ?User {
        return $this->model->find($id);
    }
}
