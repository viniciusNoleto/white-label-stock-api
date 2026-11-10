<?php

namespace App\Http\Requests\Item;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Validator;

class UpdateItemRequest extends BaseRequest {

    private const QUANTITY_FIELDS = ['quantity', 'quantity_add', 'quantity_subtract'];

    /**
     * Sem `code`: o código do item é fixo (gerado na criação) e não pode ser editado.
     *
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'quantity' => 'nullable|numeric|min:0',
            'quantity_add' => 'nullable|numeric|gt:0',
            'quantity_subtract' => 'nullable|numeric|gt:0',
            'produced_at' => 'sometimes|required|date_format:Y-m-d',
            'expires_at' => 'nullable|date_format:Y-m-d',
            'storage_id' => 'nullable',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'quantity.*' => 'messages.items.invalid_quantity',
            'quantity_add.*' => 'messages.items.invalid_quantity',
            'quantity_subtract.*' => 'messages.items.invalid_quantity',
            'produced_at.*' => 'messages.items.invalid_date',
            'expires_at.*' => 'messages.items.invalid_date',
        ];
    }

    /**
     * Payload sem nenhum campo atualizável (`storage_id: null`/`expires_at: null` contam: limpam o campo)
     * ou com mais de um modo de quantidade (definir, somar e subtrair são exclusivos).
     *
     * @return array<int, callable>
     */
    public function after(): array {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $quantity_fields = collect(self::QUANTITY_FIELDS)->filter(fn($field) => $this->input($field) !== null)->count();

                if ($quantity_fields > 1) {
                    $validator->errors()->add('payload', 'messages.items.single_quantity_mode');
                } elseif (!$quantity_fields && !$this->hasAny(['produced_at', 'expires_at', 'storage_id'])) {
                    $validator->errors()->add('payload', 'messages.items.no_fields');
                }
            },
        ];
    }
}
