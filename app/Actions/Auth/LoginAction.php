<?php

namespace App\Actions\Auth;

use App\Data\Auth\LoginDTO;
use App\Data\Auth\SessionDTO;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class LoginAction {

    public function __construct(
        private readonly CreateAccessTokenAction  $createAccessToken,
        private readonly CreateRefreshTokenAction $createRefreshToken
    ) {
    }

    /**
     * E-mail inexistente e senha errada respondem a mesma mensagem.
     *
     * @param LoginDTO $dto
     * @return SessionDTO
     * @throws ApiException
     */
    public function handle(LoginDTO $dto): SessionDTO {
        $user = User::query()->where('email', $dto->email)->first();

        if (!$user || !Hash::check($dto->password, $user->password)) {
            throw new ApiException('messages.auth.invalid_credentials', 401);
        }

        return new SessionDTO(
            user: $user,
            access_token: $this->createAccessToken->handle($user->id),
            refresh_token: $this->createRefreshToken->handle($user->id)
        );
    }
}
