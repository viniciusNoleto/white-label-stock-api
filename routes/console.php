<?php

use App\Actions\Auth\PruneExpiredTokensAction;
use App\Actions\User\SetUserPasswordAction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('auth:prune-tokens', function (PruneExpiredTokensAction $action) {
    $deleted = $action->handle();

    $this->info("Tokens vencidos apagados: {$deleted['access_tokens']} access, {$deleted['refresh_tokens']} refresh.");
})->purpose('Apaga access e refresh tokens vencidos');

// Recuperação de acesso pelo operador do servidor (ex.: o admin esqueceu a senha e não há e-mail transacional).
Artisan::command('user:reset-password {email}', function (SetUserPasswordAction $action, string $email) {
    $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

    if (!$user) {
        $this->error('Usuário não encontrado.');

        return 1;
    }

    $password = (string)$this->secret('Nova senha (mínimo 8 caracteres)');

    if (mb_strlen($password) < 8 || $password !== $this->secret('Repita a nova senha')) {
        $this->error('Senha curta demais ou as duas não conferem. Nada foi alterado.');

        return 1;
    }

    $action->handle($user, $password);
    $this->info("Senha de {$user->email} redefinida; as sessões dele foram encerradas.");

    return 0;
})->purpose('Redefine a senha de um usuário (inclusive admin) e encerra as sessões dele');

// Rodado pelo serviço `scheduler` do docker-compose (`php artisan schedule:work`).
Schedule::command('auth:prune-tokens')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
