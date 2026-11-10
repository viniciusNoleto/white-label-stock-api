<?php

namespace App\Http\Requests\User;

use App\Http\Requests\BaseRequest;

class ResetUserPasswordRequest extends BaseRequest {

    /**
     * Mesmas regras de tamanho do cadastro.
     *
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'password' => 'required|string|min:8|max:255',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'password.required' => 'messages.auth.password_required_fields',
            'password.string' => 'messages.auth.password_required_fields',
            'password.min' => 'messages.registration_requests.password_too_short',
            'password.max' => 'messages.registration_requests.password_too_long',
        ];
    }
}
