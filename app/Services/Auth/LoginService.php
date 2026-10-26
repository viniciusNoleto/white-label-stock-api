<?php

namespace App\Services\Auth;

use App\DTO\Auth\LoginDTO;
use App\DTO\Auth\SessionDTO;
use App\Exceptions\ApiException;
use App\Repositories\Auth\UserRepository;
use Illuminate\Support\Facades\Hash;

class LoginService {

    public function __construct(
        private readonly UserRepository            $userRepository,
        private readonly CreateAccessTokenService  $createAccessTokenService,
        private readonly CreateRefreshTokenService $createRefreshTokenService
    ) {
    }

    /**
     * E-mail inexistente e senha errada respondem a mesma mensagem.
     *
     * @param LoginDTO $dto
     * @return SessionDTO
     * @throws ApiException
     */
    public function execute(LoginDTO $dto): SessionDTO {
        $user = $this->userRepository->findByEmail($dto->email);

        if (!$user || !Hash::check($dto->password, $user->password)) {
            throw new ApiException('messages.auth.invalid_credentials', 401);
        }

        return new SessionDTO(
            user: $user,
            access_token: $this->createAccessTokenService->execute($user->id),
            refresh_token: $this->createRefreshTokenService->execute($user->id)
        );
    }
}
