<?php

namespace App\Http\Requests;

use App\Base\Traits\Response;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Em `messages()` as FormRequests devolvem **chaves** de `lang/{locale}/messages.php`
 * (ex.: `messages.units.required_fields`), não textos: a primeira falha vira a mensagem
 * i18n da resposta 422. Regras sem mensagem própria caem em `messages.invalid_payload`.
 */
class BaseRequest extends FormRequest {

    use Response;

    /**
     * A autenticação é feita pelo middleware `auth` nas rotas.
     */
    public function authorize(): bool {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [];
    }

    /**
     * @param Validator $validator
     * @return never
     */
    protected function failedValidation(Validator $validator): never {
        self::failedValidationResponse($validator);
    }
}
