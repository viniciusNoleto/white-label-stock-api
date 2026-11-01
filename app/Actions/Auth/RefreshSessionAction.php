<?php

namespace App\Actions\Auth;

use App\Data\Auth\IssuedTokenDTO;
use App\Data\Auth\SessionDTO;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

final class RefreshSessionAction {

    public function __construct(
        private readonly RotateRefreshTokenAction $rotateRefreshToken,
        private readonly CreateAccessTokenAction  $createAccessToken
    ) {
    }

    /**
     * Requisições concorrentes com o mesmo refresh_token (várias abas, SSR do web + browser)
     * recebem o mesmo par novo em vez de cada uma rotacionar de novo — a segunda rotação cairia
     * na detecção de reuso e revogaria a família inteira (logout forçado).
     *
     * O lock e o resultado ficam no cache (compartilhado entre os workers do php-fpm); o par
     * novo é guardado criptografado, só pela janela de reuso.
     *
     * @param string $refresh_token
     * @return SessionDTO|null
     */
    public function handle(string $refresh_token): ?SessionDTO {
        $key = 'auth:refresh:' . hash_token($refresh_token);
        $window = config('auth_tokens.refresh_reuse_window_seconds');

        return Cache::lock($key . ':lock', $window)->block($window, function () use ($key, $window, $refresh_token) {
            $cached = Cache::get($key);

            if ($cached) {
                return $this->fromCache($cached);
            }

            $session = $this->rotate($refresh_token);

            if ($session) {
                Cache::put($key, $this->toCache($session), $window);
            }

            return $session;
        });
    }

    /**
     * @param string $refresh_token
     * @return SessionDTO|null
     */
    private function rotate(string $refresh_token): ?SessionDTO {
        $rotated = $this->rotateRefreshToken->handle($refresh_token);

        if (!$rotated) {
            return null;
        }

        [$user_id, $new_refresh_token] = $rotated;

        $user = User::find($user_id);

        if (!$user) {
            return null;
        }

        return new SessionDTO(
            user: $user,
            access_token: $this->createAccessToken->handle($user->id),
            refresh_token: $new_refresh_token
        );
    }

    /**
     * @param SessionDTO $session
     * @return string
     */
    private function toCache(SessionDTO $session): string {
        return Crypt::encrypt([
            'user_id' => $session->user->id,
            'access_token' => [$session->access_token->token, $session->access_token->expires_at->toIso8601String()],
            'refresh_token' => [$session->refresh_token->token, $session->refresh_token->expires_at->toIso8601String()],
        ]);
    }

    /**
     * @param string $cached
     * @return SessionDTO|null
     */
    private function fromCache(string $cached): ?SessionDTO {
        $data = Crypt::decrypt($cached);
        $user = User::find($data['user_id']);

        if (!$user) {
            return null;
        }

        return new SessionDTO(
            user: $user,
            access_token: new IssuedTokenDTO($data['access_token'][0], Carbon::parse($data['access_token'][1])),
            refresh_token: new IssuedTokenDTO($data['refresh_token'][0], Carbon::parse($data['refresh_token'][1]))
        );
    }
}
