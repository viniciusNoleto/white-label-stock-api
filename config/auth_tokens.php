<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sessão por tokens opacos
    |--------------------------------------------------------------------------
    |
    | Access token curto (header `Authorization: Bearer`) e refresh token longo
    | (corpo do POST /api/auth/token/refresh). O refresh é rotacionado a cada uso; reapresentar um token já rotacionado
    | revoga a família inteira (sinal de roubo).
    |
    */

    'access_token_ttl_minutes' => (int)env('AUTH_ACCESS_TOKEN_TTL_MINUTES', 15),

    'refresh_token_ttl_days' => (int)env('AUTH_REFRESH_TOKEN_TTL_DAYS', 30),

    // Refreshes concorrentes com o mesmo token (abas, SSR + browser) recebem o mesmo par novo nesta janela.
    'refresh_reuse_window_seconds' => 10,

];
