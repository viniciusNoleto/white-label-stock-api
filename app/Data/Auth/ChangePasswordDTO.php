<?php

namespace App\Data\Auth;

use App\Http\Requests\Auth\ChangePasswordRequest;
use Spatie\LaravelData\Data;

class ChangePasswordDTO extends Data {

    public function __construct(
        public string $current_password,
        public string $password,
    ) {
    }

    /**
     * @param ChangePasswordRequest $request
     * @return ChangePasswordDTO
     */
    public static function fromRequest(ChangePasswordRequest $request): ChangePasswordDTO {
        return new self(
            current_password: $request->validated('current_password'),
            password: $request->validated('password'),
        );
    }
}
