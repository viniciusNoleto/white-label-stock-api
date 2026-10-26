<?php

namespace App\DTO\Auth;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Token recém-emitido: o valor cru (vai só para o cookie) e quando expira.
 */
class IssuedTokenDTO extends Data {

    public function __construct(
        public string $token,
        public Carbon $expires_at,
        public ?string $family_id = null,
    ) {
    }
}
