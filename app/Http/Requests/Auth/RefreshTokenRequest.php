<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseRequest;

class RefreshTokenRequest extends BaseRequest {

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'refresh_token' => 'required|string',
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
