<?php

namespace App\Http\Requests\InventoryItem;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Validator;

class UpdateInventoryItemRequest extends BaseRequest {

    private const QUANTITY_FIELDS = ['quantity', 'quantity_add', 'quantity_subtract'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'quantity' => 'nullable|numeric|min:0',
            'quantity_add' => 'nullable|numeric',
            'quantity_subtract' => 'nullable|numeric',
            'name' => 'sometimes|required|string|max:255',
            'storage_id' => 'nullable',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'quantity.*' => 'messages.inventory_items.invalid_quantity',
            'quantity_add.*' => 'messages.inventory_items.invalid_quantity',
            'quantity_subtract.*' => 'messages.inventory_items.invalid_quantity',
            'name.*' => 'messages.inventory_items.name_required',
        ];
    }

    /**
     * Payload sem nenhum campo atualizável (`storage_id: null` conta: desvincula o armazenamento).
     *
     * @return array<int, callable>
     */
    public function after(): array {
        return [
            function (Validator $validator) {
                $has_quantity = collect(self::QUANTITY_FIELDS)->contains(fn($field) => $this->input($field) !== null);

                if (!$has_quantity && !$this->has('name') && !$this->has('storage_id')) {
                    $validator->errors()->add('payload', 'messages.inventory_items.no_fields');
                }
            },
        ];
    }
}
