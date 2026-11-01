<?php

namespace App\Actions\Auth;

use App\Models\Auth\AccessToken;
use App\Models\Auth\RefreshToken;

final class LogoutAction {

    /**
     * @param string|null $access_token
     * @param string|null $refresh_token
     * @return void
     */
    public function handle(?string $access_token, ?string $refresh_token): void {
        if ($access_token) {
            AccessToken::query()->byToken($access_token)->delete();
        }

        if ($refresh_token) {
            RefreshToken::query()->byToken($refresh_token)->delete();
        }
    }
}
