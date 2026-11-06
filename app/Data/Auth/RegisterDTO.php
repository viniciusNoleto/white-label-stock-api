<?php

namespace App\Data\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use Spatie\LaravelData\Data;

class RegisterDTO extends Data {

    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {
        $this->name = trim($this->name);
        $this->email = mb_strtolower(trim($this->email));
    }

    /**
     * @param RegisterRequest $request
     * @return RegisterDTO
     */
    public static function fromRequest(RegisterRequest $request): RegisterDTO {
        return new self(
            name: $request->validated('name'),
            email: $request->validated('email'),
            password: $request->validated('password'),
        );
    }
}
