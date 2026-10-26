<?php

namespace App\DTO\Auth;

use App\Http\Requests\Auth\LoginRequest;
use Spatie\LaravelData\Data;

class LoginDTO extends Data {

    public function __construct(
        public string $email,
        public string $password,
    ) {
        $this->email = mb_strtolower(trim($this->email));
    }

    /**
     * @param LoginRequest $request
     * @return LoginDTO
     */
    public static function fromRequest(LoginRequest $request): LoginDTO {
        return new self(
            email: $request->validated('email'),
            password: $request->validated('password'),
        );
    }
}
