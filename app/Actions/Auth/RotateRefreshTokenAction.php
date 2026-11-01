<?php

namespace App\Actions\Auth;

use App\Data\Auth\IssuedTokenDTO;
use App\Models\Auth\RefreshToken;
use Illuminate\Support\Facades\DB;

final class RotateRefreshTokenAction {

    public function __construct(
        private readonly CreateRefreshTokenAction $createRefreshToken
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
    public function handle(string $token): ?array {
        return DB::transaction(function () use ($token) {
            // Trava a linha até o fim da transação: duas rotações do mesmo token nunca correm juntas.
            $existing = RefreshToken::query()->byToken($token)->lockForUpdate()->first();

            if (!$existing || $existing->isExpired()) {
                return null;
            }

            if ($existing->revoked) {
                RefreshToken::query()->where('family_id', $existing->family_id)->update(['revoked' => true]);

                return null;
            }

            RefreshToken::query()->whereKey($existing->id)->update(['revoked' => true]);

            return [
                $existing->user_id,
                $this->createRefreshToken->handle($existing->user_id, $existing->family_id),
            ];
        });
    }
}
