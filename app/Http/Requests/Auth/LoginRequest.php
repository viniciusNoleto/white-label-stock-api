<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseRequest;

class LoginRequest extends BaseRequest {

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'email' => 'required|string|max:255',
            'password' => 'required|string',
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
