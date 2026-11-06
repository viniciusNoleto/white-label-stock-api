<?php

namespace App\Actions\Auth;

use App\Data\Auth\LoginDTO;
use App\Data\Auth\SessionDTO;
use App\Exceptions\ApiException;
use App\Models\Auth\RegistrationRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class LoginAction {

    public function __construct(
        private readonly CreateAccessTokenAction  $createAccessToken,
        private readonly CreateRefreshTokenAction $createRefreshToken
    ) {
    }

    /**
     * E-mail inexistente e senha errada respondem a mesma mensagem. Pedido de cadastro pendente → 403.
     *
     * @param LoginDTO $dto
     * @return SessionDTO
     * @throws ApiException
     */
    public function handle(LoginDTO $dto): SessionDTO {
        $user = User::query()->where('email', $dto->email)->first();

        if (!$user || !Hash::check($dto->password, $user->password)) {
            // Cadastro ainda não aprovado: só avisa quem acertou a senha do próprio pedido.
            $pending = RegistrationRequest::query()->where('email', $dto->email)->first();

            if ($pending && Hash::check($dto->password, $pending->password)) {
                throw new ApiException('messages.auth.pending_approval', 403);
            }

            throw new ApiException('messages.auth.invalid_credentials', 401);
        }

        return new SessionDTO(
            user: $user,
            access_token: $this->createAccessToken->handle($user->id),
            refresh_token: $this->createRefreshToken->handle($user->id)
        );
    }
}
