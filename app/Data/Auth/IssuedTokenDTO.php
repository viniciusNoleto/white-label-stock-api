<?php

namespace App\Data\Auth;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Token recém-emitido: o valor cru (vai só na resposta, nunca para o banco) e quando expira.
 */
class IssuedTokenDTO extends Data {

    public function __construct(
        public string $token,
        public Carbon $expires_at,
        public ?string $family_id = null,
    ) {
    }
}
