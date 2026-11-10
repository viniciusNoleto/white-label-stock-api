<?php

namespace App\Actions\Auth;

use App\Actions\User\SetUserPasswordAction;
use App\Data\Auth\ChangePasswordDTO;
use App\Data\Auth\SessionDTO;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

final class ChangePasswordAction {

    public function __construct(
        private readonly SetUserPasswordAction    $setPassword,
        private readonly CreateAccessTokenAction  $createAccessToken,
        private readonly CreateRefreshTokenAction $createRefreshToken,
    ) {
    }

    /**
     * Troca de senha do próprio usuário: confere a senha atual (422 se errada; a rota tem rate limit
     * contra quem tenta adivinhá-la com um token roubado), grava a nova, encerra todas as sessões e
     * devolve uma sessão nova para quem trocou — as outras abas e aparelhos precisam entrar de novo.
     *
     * @param User $user
     * @param ChangePasswordDTO $dto
     * @return SessionDTO
     * @throws ApiException
     * @throws Throwable
     */
    public function handle(User $user, ChangePasswordDTO $dto): SessionDTO {
        if (!Hash::check($dto->current_password, $user->password)) {
            throw new ApiException('messages.auth.current_password_invalid', 422);
        }

        return DB::transaction(function () use ($user, $dto) {
            $this->setPassword->handle($user, $dto->password);

            $refresh_token = $this->createRefreshToken->handle($user->id);

            return new SessionDTO(
                user: $user,
                access_token: $this->createAccessToken->handle($user->id, $refresh_token->family_id),
                refresh_token: $refresh_token
            );
        }, attempts: config('database.transaction_attempts'));
    }
}
