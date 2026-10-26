<?php

return [

    // Primeira conta de acesso, criada por `make db-seed` (não há cadastro público).
    'admin' => [
        'name' => env('SEED_ADMIN_NAME'),
        'email' => env('SEED_ADMIN_EMAIL'),
        'password' => env('SEED_ADMIN_PASSWORD'),
    ],

];
