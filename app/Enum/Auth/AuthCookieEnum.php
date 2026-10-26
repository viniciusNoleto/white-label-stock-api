<?php

namespace App\Enum\Auth;

/**
 * Nomes dos cookies de sessão. Ambos são httpOnly e só a API os grava/apaga —
 * nenhum código do browser lê ou escreve esses cookies.
 */
enum AuthCookieEnum: string {
    case ACCESS_TOKEN = 'access_token';
    case REFRESH_TOKEN = 'refresh_token';
}
