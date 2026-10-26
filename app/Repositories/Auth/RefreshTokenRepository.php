<?php

namespace App\Repositories\Auth;

use App\Base\Repository\BaseRepository;
use App\Models\Auth\RefreshToken;

class RefreshTokenRepository extends BaseRepository {

    public function __construct() {
        $this->setModel(RefreshToken::class);
    }

    /**
     * Trava a linha até o fim da transação: duas rotações do mesmo token nunca correm juntas.
     *
     * @param string $token
     * @return RefreshToken|null
     */
    public function findByTokenForUpdate(string $token): ?RefreshToken {
        return $this->model->byToken($token)->lockForUpdate()->first();
    }

    /**
     * @param int $id
     * @return void
     */
    public function revoke(int $id): void {
        $this->model->whereKey($id)->update(['revoked' => true]);
    }

    /**
     * @param string $family_id
     * @return void
     */
    public function revokeFamily(string $family_id): void {
        $this->model->where('family_id', $family_id)->update(['revoked' => true]);
    }

    /**
     * @param string $token
     * @return void
     */
    public function deleteByToken(string $token): void {
        $this->model->byToken($token)->delete();
    }
}
