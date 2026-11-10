<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache do painel (GET /api/dashboard)
    |--------------------------------------------------------------------------
    |
    | O painel é aberto o tempo todo (página inicial, atualização automática) e
    | agrega o estoque inteiro. O resultado fica no cache e só é recalculado
    | quando algo mudou (versão trocada por toda escrita de estoque, pacote ou
    | produto), quando o dia do negócio vira ou depois de `max_age_seconds`
    | (o que não troca a versão: cadastro de unidade, "vencendo" que muda com a hora).
    | Uma reconstrução por vez; os outros recebem a versão anterior.
    |
    */

    'max_age_seconds' => (int)env('DASHBOARD_CACHE_MAX_AGE_SECONDS', 300),

    // Quanto tempo uma entrada fica guardada (só é servida se ainda for válida pelas regras acima).
    'store_seconds' => 86400,

    // Validade do lock de reconstrução (se o processo morrer no meio, o lock some sozinho).
    'build_lock_seconds' => 30,

    // Sem nenhuma entrada para servir, quanto tempo esperar a reconstrução em andamento antes de calcular por conta própria.
    'build_wait_seconds' => 10,

];
