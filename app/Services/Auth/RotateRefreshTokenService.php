<?php

namespace App\Services\Auth;

use App\DTO\Auth\IssuedTokenDTO;
use App\Repositories\Auth\RefreshTokenRepository;
use Illuminate\Support\Facades\DB;

class RotateRefreshTokenService {

    public function __construct(
        private readonly RefreshTokenRepository    $refreshTokenRepository,
        private readonly CreateRefreshTokenService $createRefreshTokenService
    ) {
    }

    /**
     * Revoga o token usado e emite um novo na mesma família. Retorna `[user_id, token]` ou `null`
     * quando o token não existe, expirou ou já foi rotacionado — neste último caso alguém ficou com
     * uma cópia antiga: revoga a família inteira, derrubando todas as sessões que dependem dela.
     *
     * @param string $token
     * @return array{0: int, 1: IssuedTokenDTO}|null
     */
    public function execute(string $token): ?array {
        return DB::transaction(function () use ($token) {
            $existing = $this->refreshTokenRepository->findByTokenForUpdate($token);

            if (!$existing || $existing->isExpired()) {
                return null;
            }

            if ($existing->revoked) {
                $this->refreshTokenRepository->revokeFamily($existing->family_id);

                return null;
            }

            $this->refreshTokenRepository->revoke($existing->id);

            return [
                $existing->user_id,
                $this->createRefreshTokenService->execute($existing->user_id, $existing->family_id),
            ];
        });
    }
}
