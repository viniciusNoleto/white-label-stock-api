<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseRequest;

/**
 * O access token vem no header `Authorization: Bearer`; o refresh token (opcional) no corpo.
 */
class LogoutRequest extends BaseRequest {

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'refresh_token' => 'nullable|string',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            '*' => 'messages.invalid_payload',
        ];
    }
}
