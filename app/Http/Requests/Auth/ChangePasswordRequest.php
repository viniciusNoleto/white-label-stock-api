<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseRequest;

class ChangePasswordRequest extends BaseRequest {

    /**
     * Mesmas regras de tamanho do cadastro.
     *
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|max:255',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            '*.required' => 'messages.auth.password_required_fields',
            '*.string' => 'messages.auth.password_required_fields',
            'password.min' => 'messages.registration_requests.password_too_short',
            'password.max' => 'messages.registration_requests.password_too_long',
        ];
    }
}
