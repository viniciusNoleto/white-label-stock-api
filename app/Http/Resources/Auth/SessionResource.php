<?php

namespace App\Http\Resources\Auth;

use App\Data\Auth\IssuedTokenDTO;
use App\Data\Auth\SessionDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sessão emitida no login/refresh. Os tokens são opacos; `expires_in`/`refresh_expires_in`
 * são os segundos até cada um expirar (o web usa como `max-age` dos cookies).
 *
 * @property SessionDTO $resource
 */
class SessionResource extends JsonResource {

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'user' => AuthUserResource::make($this->resource->user),
            'access_token' => $this->resource->access_token->token,
            'refresh_token' => $this->resource->refresh_token->token,
            'expires_in' => self::secondsUntil($this->resource->access_token),
            'refresh_expires_in' => self::secondsUntil($this->resource->refresh_token),
        ];
    }

    /**
     * @param IssuedTokenDTO $token
     * @return int
     */
    private static function secondsUntil(IssuedTokenDTO $token): int {
        return max(0, (int)now()->diffInSeconds($token->expires_at, false));
    }
}
