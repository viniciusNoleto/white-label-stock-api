<?php

namespace App\Actions\Auth\RegistrationRequest;

use App\Data\Auth\RegisterDTO;
use App\Exceptions\ApiException;
use App\Models\Auth\RegistrationRequest;
use App\Models\User;
use Illuminate\Database\QueryException;

final class CreateRegistrationRequestAction {

    /**
     * E-mail já usado por um usuário ou por outro pedido → 409 (a corrida do índice único também).
     *
     * @param RegisterDTO $dto
     * @return RegistrationRequest
     * @throws ApiException
     */
    public function handle(RegisterDTO $dto): RegistrationRequest {
        if (User::query()->where('email', $dto->email)->exists() || RegistrationRequest::query()->where('email', $dto->email)->exists()) {
            throw new ApiException('messages.registration_requests.email_taken', 409);
        }

        try {
            return RegistrationRequest::create($dto->toArray());
        } catch (QueryException $exception) {
            if (is_unique_violation($exception)) {
                throw new ApiException('messages.registration_requests.email_taken', 409);
            }

            throw $exception;
        }
    }
}
