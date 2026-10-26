<?php

namespace App\Base\Traits;

use App\DTO\Auth\IssuedTokenDTO;
use App\DTO\Auth\SessionDTO;
use App\Enum\Auth\AuthCookieEnum;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Cookies de sessão: httpOnly, SameSite=Lax, Path=/, Secure em produção (`COOKIE_SECURE` sobrescreve).
 * O cookie de access expira junto com o token: quando o browser o descarta, o web renova via refresh.
 */
trait AuthCookies {

    /**
     * @param JsonResponse $response
     * @param SessionDTO $session
     * @return JsonResponse
     */
    public static function withAuthCookies(JsonResponse $response, SessionDTO $session): JsonResponse {
        $response->headers->setCookie(self::makeAuthCookie(AuthCookieEnum::ACCESS_TOKEN, $session->access_token));
        $response->headers->setCookie(self::makeAuthCookie(AuthCookieEnum::REFRESH_TOKEN, $session->refresh_token));

        return $response;
    }

    /**
     * @param JsonResponse $response
     * @return JsonResponse
     */
    public static function withoutAuthCookies(JsonResponse $response): JsonResponse {
        foreach (AuthCookieEnum::cases() as $cookie) {
            $response->headers->setCookie(self::baseCookie($cookie->value, '', 1));
        }

        return $response;
    }

    /**
     * @param AuthCookieEnum $cookie
     * @param IssuedTokenDTO $token
     * @return Cookie
     */
    private static function makeAuthCookie(AuthCookieEnum $cookie, IssuedTokenDTO $token): Cookie {
        return self::baseCookie($cookie->value, $token->token, $token->expires_at->getTimestamp());
    }

    /**
     * @param string $name
     * @param string $value
     * @param int $expires
     * @return Cookie
     */
    private static function baseCookie(string $name, string $value, int $expires): Cookie {
        return Cookie::create(
            name: $name,
            value: $value,
            expire: $expires,
            path: '/',
            secure: (bool)config('auth_tokens.cookie_secure'),
            httpOnly: true,
            raw: true,
            sameSite: Cookie::SAMESITE_LAX
        );
    }
}
