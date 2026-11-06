<?php

namespace App\Actions\Auth\RegistrationRequest;

use App\Models\Auth\RegistrationRequest;

final class RejectRegistrationRequestAction {

    /**
     * Pedido recusado não fica guardado: é excluído (a pessoa pode pedir de novo).
     *
     * @param mixed $id
     * @return void
     */
    public function handle(mixed $id): void {
        RegistrationRequest::findOrFailByKey($id, 'messages.registration_requests.not_found')->delete();
    }
}
