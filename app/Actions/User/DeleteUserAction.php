<?php

namespace App\Actions\User;

use App\Exceptions\ApiException;
use App\Models\User;

final class DeleteUserAction {

    /**
     * Só usuários comuns podem ser excluídos (admin → 422). Os tokens saem em cascata: o acesso acaba na hora.
     *
     * @param mixed $id
     * @return void
     * @throws ApiException
     */
    public function handle(mixed $id): void {
        $user = User::findOrFailByKey($id, 'messages.users.not_found');

        if ($user->is_admin) {
            throw new ApiException('messages.users.cannot_delete_admin', 422);
        }

        $user->delete();
    }
}
