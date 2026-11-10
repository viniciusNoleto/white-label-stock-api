<?php

namespace App\Actions\Auth;

use App\Data\Auth\LoginDTO;
use App\Data\Auth\SessionDTO;
use App\Exceptions\ApiException;
use App\Models\Auth\RegistrationRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class LoginAction {

    /** Hash descartável para gastar o mesmo bcrypt quando o e-mail não existe (calculado uma vez por worker). */
    private static ?string $dummy_hash = null;

    public function __construct(
        private readonly CreateAccessTokenAction  $createAccessToken,
        private readonly CreateRefreshTokenAction $createRefreshToken
    ) {
    }

    /**
     * E-mail inexistente e senha errada respondem a mesma mensagem **e levam o mesmo tempo**: todo
     * caminho faz exatamente uma verificação de bcrypt (com um hash descartável quando não há conta),
     * então o tempo de resposta não revela quais e-mails existem. Pedido de cadastro pendente com a
     * senha certa → 403 (só quem sabe a senha do pedido descobre que ele existe).
     * A força bruta é contida pelo rate limit da rota (`throttle:login`).
     *
     * @param LoginDTO $dto
     * @return SessionDTO
     * @throws ApiException
     */
    public function handle(LoginDTO $dto): SessionDTO {
        $user = User::query()->where('email', $dto->email)->first();

        if ($user) {
            if (!Hash::check($dto->password, $user->password)) {
                throw new ApiException('messages.auth.invalid_credentials', 401);
            }

            $refresh_token = $this->createRefreshToken->handle($user->id);

            return new SessionDTO(
                user: $user,
                access_token: $this->createAccessToken->handle($user->id, $refresh_token->family_id),
                refresh_token: $refresh_token
            );
        }

        $pending = RegistrationRequest::query()->where('email', $dto->email)->first();

        if (Hash::check($dto->password, $pending->password ?? self::dummyHash()) && $pending) {
            throw new ApiException('messages.auth.pending_approval', 403);
        }

        throw new ApiException('messages.auth.invalid_credentials', 401);
    }

    /**
     * @return string
     */
    private static function dummyHash(): string {
        return self::$dummy_hash ??= Hash::make(generate_opaque_token());
    }
}
