<?php

namespace App\Data\Auth;

use App\Models\User;

/**
 * Par de tokens emitido para um usuário (login ou refresh).
 */
class SessionDTO {

    public function __construct(
        public User           $user,
        public IssuedTokenDTO $access_token,
        public IssuedTokenDTO $refresh_token,
    ) {
    }
}
