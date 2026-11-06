<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseRequest;

class RegisterRequest extends BaseRequest {

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'name' => 'required|string|max:150',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|max:255',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            '*.required' => 'messages.registration_requests.required_fields',
            '*.string' => 'messages.registration_requests.required_fields',
            'name.max' => 'messages.registration_requests.name_too_long',
            'email.email' => 'messages.registration_requests.invalid_email',
            'email.max' => 'messages.registration_requests.invalid_email',
            'password.min' => 'messages.registration_requests.password_too_short',
            'password.max' => 'messages.registration_requests.password_too_long',
        ];
    }
}
