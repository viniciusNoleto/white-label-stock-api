<?php

namespace App\Data\User;

use App\Http\Requests\User\ResetUserPasswordRequest;
use Spatie\LaravelData\Data;

class ResetUserPasswordDTO extends Data {

    public function __construct(
        public string $password,
    ) {
    }

    /**
     * @param ResetUserPasswordRequest $request
     * @return ResetUserPasswordDTO
     */
    public static function fromRequest(ResetUserPasswordRequest $request): ResetUserPasswordDTO {
        return new self(
            password: $request->validated('password'),
        );
    }
}
