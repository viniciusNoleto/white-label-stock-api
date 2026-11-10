<?php

namespace App\Actions\User;

use App\Data\User\ResetUserPasswordDTO;
use App\Exceptions\ApiException;
use App\Models\User;
use Throwable;

final class ResetUserPasswordAction {

    public function __construct(
        private readonly SetUserPasswordAction $setPassword,
    ) {
    }

    /**
     * Admin define uma senha nova para um usuário comum que a esqueceu (não há e-mail transacional para
     * um "esqueci minha senha"); as sessões dele caem. A senha de outro admin não → 422: um admin não
     * assume a conta de outro. Admin que esqueceu a própria senha: `php artisan user:reset-password` no servidor.
     *
     * @param mixed $id
     * @param ResetUserPasswordDTO $dto
     * @return void
     * @throws ApiException
     * @throws Throwable
     */
    public function handle(mixed $id, ResetUserPasswordDTO $dto): void {
        $user = User::findOrFailByKey($id, 'messages.users.not_found');

        if ($user->is_admin) {
            throw new ApiException('messages.users.cannot_reset_admin', 422);
        }

        $this->setPassword->handle($user, $dto->password);
    }
}
