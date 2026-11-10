<?php

namespace App\Actions\User;

use App\Models\Auth\AccessToken;
use App\Models\Auth\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SetUserPasswordAction {

    /**
     * Grava a nova senha (o cast `hashed` do `User` faz o bcrypt) e encerra **todas** as sessões do
     * usuário, na mesma transação: quem tinha a senha antiga (ou um token roubado) perde o acesso na hora.
     * Usada pela troca de senha do próprio usuário, pelo reset do admin e pelo comando `user:reset-password`.
     *
     * @param User $user
     * @param string $password em texto puro
     * @return void
     * @throws Throwable
     */
    public function handle(User $user, string $password): void {
        DB::transaction(function () use ($user, $password) {
            $user->update(['password' => $password]);

            AccessToken::query()->where('user_id', $user->id)->delete();
            RefreshToken::query()->where('user_id', $user->id)->delete();
        }, attempts: config('database.transaction_attempts'));
    }
}
