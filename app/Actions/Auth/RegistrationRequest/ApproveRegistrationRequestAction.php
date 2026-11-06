<?php

namespace App\Actions\Auth\RegistrationRequest;

use App\Exceptions\ApiException;
use App\Models\Auth\RegistrationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ApproveRegistrationRequestAction {

    /**
     * Cria o usuário (comum, não admin) com a senha do pedido — já com hash — e exclui o pedido.
     *
     * @param mixed $id
     * @return User
     * @throws ApiException
     */
    public function handle(mixed $id): User {
        return DB::transaction(function () use ($id) {
            $parsed_id = parse_id($id);
            $request = $parsed_id ? RegistrationRequest::query()->lockForUpdate()->find($parsed_id) : null;

            if (!$request) {
                throw new ApiException('messages.registration_requests.not_found', 404);
            }

            if (User::query()->where('email', $request->email)->exists()) {
                throw new ApiException('messages.registration_requests.email_taken', 409);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'is_admin' => false,
            ]);

            $request->delete();

            return $user->fresh();
        });
    }
}
